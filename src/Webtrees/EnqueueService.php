<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use DateTimeImmutable;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Support\FinderRequestFactory;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

use function array_keys;
use function count;
use function reset;
use function sort;

use const SORT_STRING;

/**
 * The enqueue producer: selects the death-date-missing candidates of a tree, drops any already
 * in-flight, attaches each survivor's prioritised queries plus the portals it already has an open
 * match on, and enqueues one bounded finder job for the private finder to claim.
 *
 * Lives in the {@see \MagicSunday\ObituaryMatcher\Webtrees} adapter layer (it orchestrates
 * {@see TreeService}, {@see CandidateRepository}, the per-tree {@see MatchStore} and the
 * {@see JobTransport}); the pure {@see FinderRequestFactory}/{@see UrlHostNormalizer}/{@see JobId}
 * are injected. The in-flight scan + the excludedHosts are advisory dedup hints — the slice stays
 * correct (and the drain idempotent) even if the finder ignores them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class EnqueueService
{
    /**
     * Constructor.
     *
     * @param CandidateRepository  $repository     The candidate selector.
     * @param FinderRequestFactory $requestFactory The pure request assembler.
     * @param UrlHostNormalizer    $hostNormalizer The canonical-host helper for excludedHosts.
     * @param TreeService          $treeService    The webtrees tree lookup (throws on an unknown id).
     * @param JobTransport         $transport      The transport that submits the job and exposes the in-flight set.
     */
    public function __construct(
        private readonly CandidateRepository $repository,
        private readonly FinderRequestFactory $requestFactory,
        private readonly UrlHostNormalizer $hostNormalizer,
        private readonly TreeService $treeService,
        private readonly JobTransport $transport,
    ) {
    }

    /**
     * Selects, dedups, bounds and enqueues one finder job for the tree.
     *
     * @param int      $treeId        The numeric tree id to enqueue (throws DomainException if unknown).
     * @param int      $limit         The maximum candidates written into the one job this run.
     * @param int      $minAge        The minimum age for {@see CandidateCriteria}.
     * @param string   $locale        The IETF BCP 47 locale tag stamped onto the request.
     * @param int|null $referenceYear The age reference year, or null for the current year.
     *
     * @return EnqueueSummary The run tally.
     */
    public function enqueue(int $treeId, int $limit, int $minAge, string $locale, ?int $referenceYear = null): EnqueueSummary
    {
        $tree = $this->treeService->find($treeId);

        // A non-positive cap enqueues nothing. Bound it explicitly: the `count($eligible) === $limit`
        // break below can never fire for $limit <= 0 (the count starts at 1 after the first append),
        // so without this guard the loop would drain and enqueue the WHOLE eligible population. This
        // mirrors the old `array_slice($eligible, 0, $limit)`, which returned [] for $limit <= 0.
        if ($limit < 1) {
            return new EnqueueSummary(null, 0, 0, 0);
        }

        // Tree-filtered: only jobs for THIS tree block a re-enqueue, so a shared xref (I1 in tree A
        // vs I1 in tree B) never causes a cross-tree false-positive skip.
        $inFlight = $this->collectInFlightPersonIds($treeId);

        // Pull candidates lazily, already in lowest-xref-first order, and stop the moment the cap is
        // filled. The generator hydrates one candidate per pull, so a run on a large tree pays at
        // most O(limit + in-flight-stepped-over) individual hydrations instead of materialising the
        // whole eligible population only to slice off --limit of it (issue #38).
        $eligible = [];
        $skipped  = 0;

        foreach (
            $this->repository->findCandidatesLazily(
                $tree,
                new CandidateCriteria($minAge, false, $referenceYear),
            ) as $candidate
        ) {
            if (isset($inFlight[$candidate->id])) {
                ++$skipped;

                continue;
            }

            // A candidate whose name collapsed to empty placeholders (webtrees `@P.N.`/`@N.N.`) cannot
            // form a valid CandidateFacts.names entry (the contract's `minItems: 1`) and is unsearchable
            // anyway; drop it silently so a single nameless person cannot invalidate the whole POST body.
            // The EnqueueSummary candidate count therefore reflects only the nameable survivors.
            if (!$candidate->name->hasSearchableName()) {
                continue;
            }

            $eligible[] = $candidate;

            if (count($eligible) === $limit) {
                // The cap is filled; break so the generator suspends and the remaining candidates
                // are never hydrated. skippedInflight therefore counts the in-flight candidates
                // stepped over WHILE filling this batch (those with a lower xref than the cap
                // boundary), not the whole-population in-flight total.
                break;
            }
        }

        if ($eligible === []) {
            return new EnqueueSummary(null, 0, $skipped, 0);
        }

        return $this->submitJob($tree, $eligible, $locale, $skipped);
    }

    /**
     * Enqueues a single, manager-chosen person into the search — a finder job for exactly that individual,
     * reusing the same producer path as the auto-selecting {@see self::enqueue()}. Returns a null-jobId
     * summary when the xref is unknown, invisible to the principal or a nameless placeholder (nothing to
     * search), or a skipped=1 summary when the person already has a job in flight for this tree (no
     * duplicate is enqueued).
     *
     * @param int    $treeId The numeric tree id (throws DomainException if unknown).
     * @param string $xref   The chosen individual's xref.
     * @param string $locale The IETF BCP 47 locale tag stamped onto the request.
     *
     * @return EnqueueSummary The run tally.
     */
    public function enqueueOne(int $treeId, string $xref, string $locale): EnqueueSummary
    {
        $tree       = $this->treeService->find($treeId);
        $candidates = $this->repository->findByXrefs($tree, [$xref]);
        $candidate  = $candidates === [] ? null : reset($candidates);

        // Unknown xref, a person the principal may not see (findByXrefs applies the privacy gate), or a
        // name that collapsed to webtrees placeholders (`@P.N.`/`@N.N.`) — nothing searchable to enqueue.
        if (
            !($candidate instanceof PersonCandidate)
            || !$candidate->name->hasSearchableName()
        ) {
            return new EnqueueSummary(null, 0, 0, 0);
        }

        // Do not double-enqueue a person who already has a job in flight for this tree.
        $inFlight = $this->collectInFlightPersonIds($treeId);

        if (isset($inFlight[$candidate->id])) {
            return new EnqueueSummary(null, 0, 1, 0);
        }

        return $this->submitJob($tree, [$candidate], $locale, 0);
    }

    /**
     * Builds and submits one finder job for the given eligible candidates (computing each candidate's
     * excludedHosts hint, minting the jobId + createdAt from ONE clock read so they can never straddle a
     * one-second boundary), then returns the run tally. Shared by the auto-selecting {@see self::enqueue()}
     * and the single-person {@see self::enqueueOne()}.
     *
     * @param Tree                  $tree     The tree the job belongs to.
     * @param list<PersonCandidate> $eligible The candidates to enqueue (already name-filtered and deduped).
     * @param string                $locale   The IETF BCP 47 locale tag stamped onto the request.
     * @param int                   $skipped  The in-flight candidates skipped, carried onto the summary.
     *
     * @return EnqueueSummary The run tally.
     */
    private function submitJob(Tree $tree, array $eligible, string $locale, int $skipped): EnqueueSummary
    {
        $store                   = $this->storeForTree($tree);
        $excludedHostsByPersonId = [];
        $excludedHostTotal       = 0;

        foreach ($eligible as $candidate) {
            $hosts = $this->excludedHostsFor($store, $candidate->id);

            if ($hosts !== []) {
                $excludedHostsByPersonId[$candidate->id] = $hosts;
                $excludedHostTotal += count($hosts);
            }
        }

        $now     = $this->now();
        $jobId   = JobId::mint($now);
        $request = $this->requestFactory->build(
            $jobId,
            $now,
            $locale,
            $eligible,
            $tree->id(),
            $excludedHostsByPersonId,
        );

        $this->transport->submit($request);

        return new EnqueueSummary($jobId, count($eligible), $skipped, $excludedHostTotal);
    }

    /**
     * The per-tree match store. A seam (mirroring {@see DrainService::storeForTree()}) so a test can
     * redirect it to an isolated directory.
     *
     * @param Tree $tree The tree whose store is requested.
     *
     * @return MatchStore The tree-scoped match store.
     */
    protected function storeForTree(Tree $tree): MatchStore
    {
        return MatchStoreFactory::forTree($tree);
    }

    /**
     * The reference clock for the createdAt + jobId. A seam so a test can pin a fixed instant.
     *
     * @return DateTimeImmutable The current instant.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /**
     * The canonical hosts a candidate already has an OPEN (non-terminal) match on, deduped + sorted.
     * Confirmed/Rejected contribute nothing (terminal); a URL that will not normalise is dropped.
     *
     * @param MatchStore $store    The per-tree store.
     * @param string     $personId The candidate id.
     *
     * @return list<string> The sorted, deduplicated excluded hosts.
     */
    private function excludedHostsFor(MatchStore $store, string $personId): array
    {
        $hosts = [];

        foreach ($store->findByPerson($personId) as $match) {
            if ($match->status->isTerminal()) {
                continue;
            }

            $host = $this->hostNormalizer->canonicalHost($match->obituaryUrl);

            if ($host === null) {
                // An unnormalisable stored URL is a warning, not a failure (spec §10); drop it.
                continue;
            }

            $hosts[$host] = true;
        }

        $hosts = array_keys($hosts);
        sort($hosts, SORT_STRING);

        return $hosts;
    }

    /**
     * Iterates every in-flight job's request through the transport and returns the union of its
     * requested person ids as a set, FILTERED to this tree. Tree-filtered on the request's own
     * `treeId`, so a shared xref across trees never causes a cross-tree false-positive skip. The
     * transport is best-effort: a malformed in-flight request is already skipped at the source, so one
     * corrupt foreign job cannot block the producer.
     *
     * @param int $treeId The tree being enqueued; only same-tree jobs block a re-enqueue.
     *
     * @return array<string, true> The set of already-in-flight person ids for this tree.
     */
    private function collectInFlightPersonIds(int $treeId): array
    {
        $inFlight = [];

        foreach ($this->transport->inFlightRequests() as $request) {
            // Only a job for THIS tree blocks a re-enqueue (a shared xref in another tree's job is a
            // different person). request.json carries treeId since 2e-1, so this is reliable.
            if ($request['treeId'] !== $treeId) {
                continue;
            }

            foreach ($request['requestedPersonIds'] as $personId) {
                $inFlight[$personId] = true;
            }
        }

        return $inFlight;
    }
}
