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
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use RuntimeException;

use function array_keys;
use function count;
use function is_dir;
use function scandir;
use function sort;

use const SCANDIR_SORT_NONE;
use const SORT_STRING;

/**
 * The enqueue producer: selects the death-date-missing candidates of a tree, drops any already
 * in-flight, attaches each survivor's prioritised queries plus the portals it already has an open
 * match on, and enqueues one bounded feeder job for the private feeder to claim.
 *
 * Lives in the {@see \MagicSunday\ObituaryMatcher\Webtrees} adapter layer (it orchestrates
 * {@see TreeService}, {@see CandidateRepository}, the per-tree {@see MatchStore} and the
 * {@see QueueClient}); the pure {@see FeederRequestFactory}/{@see UrlHostNormalizer}/{@see JobId}
 * are injected. The in-flight scan + the excludedHosts are advisory dedup hints — the slice stays
 * correct (and the drain idempotent) even if the feeder ignores them.
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
     * @param QueuePaths           $paths          The queue path builder used for the in-flight scan.
     * @param QueueClient          $client         The queue state-machine driver (enqueue).
     * @param FeederRequestReader  $reader         The validating reader for an in-flight request.json.
     * @param CandidateRepository  $repository     The candidate selector.
     * @param FeederRequestFactory $requestFactory The pure request assembler.
     * @param UrlHostNormalizer    $hostNormalizer The canonical-host helper for excludedHosts.
     * @param TreeService          $treeService    The webtrees tree lookup (throws on an unknown id).
     */
    public function __construct(
        private readonly QueuePaths $paths,
        private readonly QueueClient $client,
        private readonly FeederRequestReader $reader,
        private readonly CandidateRepository $repository,
        private readonly FeederRequestFactory $requestFactory,
        private readonly UrlHostNormalizer $hostNormalizer,
        private readonly TreeService $treeService,
    ) {
    }

    /**
     * Selects, dedups, bounds and enqueues one feeder job for the tree.
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

        // Read the clock ONCE and stamp both the jobId and the createdAt from the same instant, so the
        // two can never straddle a one-second boundary across two separate now() reads.
        $now     = $this->now();
        $jobId   = JobId::mint($now);
        $request = $this->requestFactory->build(
            $jobId,
            $now,
            $locale,
            $eligible,
            $treeId,
            $excludedHostsByPersonId,
        );

        $this->client->enqueue($request);

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
     * The states in which a person counts as already in-flight. Done = the feeder produced a result
     * not yet persisted; Ingesting = the drain is mid-transition. A method (not a class constant) so
     * the enum-case list never relies on constant-expression edge cases.
     *
     * @return list<JobState> The in-flight states.
     */
    private function inFlightStates(): array
    {
        return [
            JobState::Queued,
            JobState::Running,
            JobState::Done,
            JobState::Ingesting,
        ];
    }

    /**
     * Scans every in-flight job's request FOR THIS TREE and returns the union of its requested person
     * ids as a set. Tree-filtered on the request's own `treeId`, so a shared xref across trees never
     * causes a cross-tree false-positive skip. Best-effort: a malformed in-flight request (the reader
     * throws a validation, IO or path-guard exception) is skipped, never fatal, so one corrupt foreign
     * job cannot block the producer.
     *
     * @param int $treeId The tree being enqueued; only same-tree jobs block a re-enqueue.
     *
     * @return array<string, true> The set of already-in-flight person ids for this tree.
     */
    private function collectInFlightPersonIds(int $treeId): array
    {
        $inFlight = [];

        foreach ($this->inFlightStates() as $state) {
            $root = $this->paths->stateRoot($state->value);

            if (!is_dir($root)) {
                continue;
            }

            $entries = scandir($root, SCANDIR_SORT_NONE);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!$this->paths->isJobDirectoryName($entry)) {
                    continue;
                }

                try {
                    $request = $this->reader->read($entry, $state);
                } catch (RuntimeException|InvalidArgumentException) {
                    // Warn-and-ignore: a corrupt/foreign/path-hostile in-flight job must never block
                    // the producer. A schema-invalid request surfaces as a ResponseValidationException
                    // and a broken-JSON / IO failure as a plain RuntimeException — both are
                    // RuntimeException subclasses, so the one arm covers them; the path-traversal guard
                    // throws InvalidArgumentException, which is NOT a RuntimeException, so it needs its
                    // own arm. (A logger could record $entry here; the CLI surfaces the scan as advisory.)
                    continue;
                }

                // Only a job for THIS tree blocks a re-enqueue (a shared xref in another tree's job
                // is a different person). request.json carries treeId since 2e-1, so this is reliable.
                if ($request['treeId'] !== $treeId) {
                    continue;
                }

                foreach ($request['requestedPersonIds'] as $personId) {
                    $inFlight[$personId] = true;
                }
            }
        }

        return $inFlight;
    }
}
