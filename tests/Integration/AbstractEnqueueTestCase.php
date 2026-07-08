<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use DateTimeImmutable;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\FileNegativeMemoryStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\NegativeMemoryStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;

use function hash;

/**
 * Shared harness for the enqueue integration tests: an isolated per-tree store (the plumbing lives in
 * {@see AbstractStoreTestCase}) and the {@see EnqueueService} wired through the SAME graph the
 * `tools/enqueue.php` CLI assembles (the only seams redirected are {@see EnqueueService::storeForTree()}
 * → the isolated store and {@see EnqueueService::now()} → a fixed instant). The transport is a
 * {@see RecordingJobTransport} double: tests seed the already-in-flight requests it exposes and, after
 * the run, read the submitted request back off it, so the harness never lays out an on-disk queue.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractEnqueueTestCase extends AbstractStoreTestCase
{
    /**
     * The fixed instant the enqueue double's clock is pinned to (see the anonymous class' now()).
     */
    protected const string PINNED_NOW = '2026-06-23T10:15:30+00:00';

    /**
     * @var RecordingJobTransport The transport the last enqueueService() build was wired to, so a
     *                            scenario can read the submitted request back after the run.
     */
    protected RecordingJobTransport $transport;

    /**
     * @var list<array{treeId: int, requestedPersonIds: list<string>}> The in-flight requests the next
     *                                                                 transport exposes, so the in-flight dedup scan sees those persons as already queued.
     */
    private array $inFlight = [];

    /**
     * Initialise an empty transport so a scenario that never builds the producer (e.g. the
     * throwing-producer trigger tests) can still read {@see queuedJobIds()} — it observes a producer
     * that submitted nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RecordingJobTransport();
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    protected function tempDirPrefix(): string
    {
        return 'obituary-enqueue-';
    }

    /**
     * Build the {@see EnqueueService} through the real CLI wiring over a fresh {@see RecordingJobTransport}
     * (seeded with the in-flight requests accumulated so far), redirecting only the store seam (to the
     * isolated root) and the clock seam (to a fixed instant for a stable jobId). The transport is stored
     * on {@see self::$transport} so the caller can read the submitted request back after the run.
     *
     * @return EnqueueService
     */
    protected function enqueueService(): EnqueueService
    {
        $this->transport = new RecordingJobTransport([], $this->inFlight);

        $storeDir = $this->storeRoot;

        return new class(new CandidateRepository(), new FinderRequestFactory(new QueryGenerator()), new UrlHostNormalizer(), new TreeService(new GedcomImportService()), $this->transport, $storeDir) extends EnqueueService {
            /**
             * @param CandidateRepository  $repository     The candidate repository.
             * @param FinderRequestFactory $requestFactory The request assembler.
             * @param UrlHostNormalizer    $hostNormalizer The canonical-host helper.
             * @param TreeService          $treeService    The tree lookup.
             * @param JobTransport         $transport      The job transport.
             * @param string               $storeRoot      The isolated per-tree store base directory.
             */
            public function __construct(
                CandidateRepository $repository,
                FinderRequestFactory $requestFactory,
                UrlHostNormalizer $hostNormalizer,
                TreeService $treeService,
                JobTransport $transport,
                private readonly string $storeRoot,
            ) {
                parent::__construct($repository, $requestFactory, $hostNormalizer, $treeService, $transport);
            }

            /**
             * Redirect the per-tree store to an isolated directory under the test root.
             *
             * @param Tree $tree The tree whose store is requested.
             *
             * @return MatchStore The isolated, tree-scoped store.
             */
            protected function storeForTree(Tree $tree): MatchStore
            {
                return new FileMatchStore(MatchStoreFactory::pathForTree($this->storeRoot, $tree));
            }

            /**
             * Redirect the per-tree negative-memory store to an isolated directory under the test root.
             *
             * @param Tree $tree The tree whose negative-memory store is requested.
             *
             * @return NegativeMemoryStore The isolated, tree-scoped negative-memory store.
             */
            protected function negativeMemoryStoreForTree(Tree $tree): NegativeMemoryStore
            {
                return new FileNegativeMemoryStore(MatchStoreFactory::pathForTree($this->storeRoot . '/negative-memory', $tree));
            }

            /**
             * Pin the clock so the minted jobId and the createdAt stamp are deterministic.
             *
             * @return DateTimeImmutable The fixed instant.
             */
            protected function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-06-23T10:15:30+00:00');
            }
        };
    }

    /**
     * The tree-scoped negative-memory store the enqueue double consults, read through the same layout,
     * so a test can seed a recorded genuine miss the re-search policy then acts on.
     *
     * @param Tree $tree The tree whose negative-memory store is read.
     *
     * @return NegativeMemoryStore The isolated, tree-scoped negative-memory store.
     */
    protected function negativeMemoryStoreFor(Tree $tree): NegativeMemoryStore
    {
        return new FileNegativeMemoryStore(MatchStoreFactory::pathForTree($this->storeRoot . '/negative-memory', $tree));
    }

    /**
     * The job ids the producer submitted this run, in order.
     *
     * @return list<string> The submitted job ids.
     */
    protected function queuedJobIds(): array
    {
        $ids = [];

        foreach ($this->transport->submitted as $request) {
            $ids[] = $request->jobId;
        }

        return $ids;
    }

    /**
     * The candidate list of the submitted job's request in its JSON-ready contract-wire shape (so the
     * assertions see the exact `names`/`queryHints` body the producer POSTs).
     *
     * @param string $jobId The submitted job id.
     *
     * @return list<array<string, mixed>> The candidate entries.
     */
    protected function queuedCandidates(string $jobId): array
    {
        return $this->submittedRequestFor($jobId)->toArray()['candidates'];
    }

    /**
     * The full submitted job's request in its JSON-ready contract-wire shape (schemaVersion, jobId,
     * locale, candidates) — so a scenario can validate the exact body the producer POSTs against the
     * published schema.
     *
     * @param string $jobId The submitted job id.
     *
     * @return array<string, mixed> The wire body.
     */
    protected function submittedRequestArray(string $jobId): array
    {
        return $this->submittedRequestFor($jobId)->toArray();
    }

    /**
     * The excludedHosts the producer threaded onto each submitted candidate, keyed by personId. The
     * hint is carried on the {@see FinderCandidateRequest} object but NOT on the contract wire, so this
     * reads it off the object graph rather than the serialised body.
     *
     * @param string $jobId The submitted job id.
     *
     * @return array<string, list<string>> The per-personId excludedHosts.
     */
    protected function queuedExcludedHosts(string $jobId): array
    {
        $byId = [];

        foreach ($this->submittedRequestFor($jobId)->candidates as $candidate) {
            $byId[$candidate->personId] = $candidate->excludedHosts;
        }

        return $byId;
    }

    /**
     * The personIds of the submitted job's request, in written order.
     *
     * @param string $jobId The submitted job id.
     *
     * @return list<string> The requested person ids.
     */
    protected function queuedPersonIds(string $jobId): array
    {
        $ids = [];

        foreach ($this->queuedCandidates($jobId) as $candidate) {
            /** @var string $personId */
            $personId = $candidate['personId'];
            $ids[]    = $personId;
        }

        return $ids;
    }

    /**
     * The submitted {@see FinderRequest} carrying the given job id, failing the test when the producer
     * submitted no such request.
     *
     * @param string $jobId The submitted job id.
     *
     * @return FinderRequest The matching submitted request.
     */
    private function submittedRequestFor(string $jobId): FinderRequest
    {
        foreach ($this->transport->submitted as $request) {
            if ($request->jobId === $jobId) {
                return $request;
            }
        }

        self::fail('No request was submitted for job id ' . $jobId);
    }

    /**
     * Seed an in-flight request for the given persons, so the in-flight dedup scan sees those personIds
     * as already queued for the tree.
     *
     * @param int          $treeId    The tree the in-flight request belongs to.
     * @param list<string> $personIds The requested person ids already in flight.
     *
     * @return void
     */
    protected function seedInflightJob(int $treeId, array $personIds): void
    {
        $this->inFlight[] = ['treeId' => $treeId, 'requestedPersonIds' => $personIds];
    }

    /**
     * Seed a stored match row into the isolated per-tree store in the requested status. The row is
     * always written as Pending first (the only status {@see FileMatchStore::upsertPending()} writes),
     * then transitioned to the target status via the matching store transition.
     *
     * @param Tree        $tree     The tree whose store is seeded.
     * @param string      $personId The candidate id.
     * @param string      $url      The source notice URL (its host becomes a candidate excluded host).
     * @param MatchStatus $status   The target row status.
     *
     * @return void
     */
    protected function seedStoreRow(Tree $tree, string $personId, string $url, MatchStatus $status): void
    {
        $store = new FileMatchStore(MatchStoreFactory::pathForTree($this->storeRoot, $tree));

        // upsertPending only ever writes a Pending row, so every other status is reached by following
        // it with the matching transition keyed on (personId, url).
        $store->upsertPending(
            new StoredMatch($personId, $url, MatchStatus::Pending, ClassifiedMatch::emptyArray($personId, $url)),
        );

        switch ($status) {
            case MatchStatus::Pending:
                break;

            case MatchStatus::Uncertain:
                $store->markUncertain($personId, $url, null);

                break;

            case MatchStatus::Rejected:
                $store->markRejected($personId, $url, null);

                break;

            case MatchStatus::Confirmed:
                // A valid WriteBack is awkward to assemble in a test, so the Confirmed row is written
                // directly: read the just-written Pending row back, flip its status, and re-persist it
                // through the same FileMatchStore (which validates the row key + per-person sub-dir).
                $this->writeConfirmedRow($tree, $personId, $url);

                break;
        }
    }

    /**
     * Hand-write a Confirmed row by re-persisting the existing Pending row with `status => confirmed`,
     * so the excludedHosts scan sees a terminal row on that host without building a real WriteBack.
     *
     * @param Tree   $tree     The tree whose store holds the row.
     * @param string $personId The candidate id.
     * @param string $url      The source notice URL.
     *
     * @return void
     */
    private function writeConfirmedRow(Tree $tree, string $personId, string $url): void
    {
        $row = (new StoredMatch($personId, $url, MatchStatus::Confirmed, ClassifiedMatch::emptyArray($personId, $url)))->toArray();

        $storeDir  = MatchStoreFactory::pathForTree($this->storeRoot, $tree);
        $personDir = $storeDir . '/' . hash('sha256', $personId);

        // The file name is the SHA-256 of the identity-normalised URL, the same row key the store
        // derives from the raw URL; reuse the store's own helper so the layout cannot drift.
        $rowKey = StoredMatchKey::fromUrl($url);

        AtomicFile::ensureDirectory($personDir);
        AtomicFile::writeJson($personDir . '/' . $rowKey . '.json', $row);
    }

    /**
     * Import a multi-person tree of old "Searchable" individuals (I1..I3) with no death date — so
     * every one is a rebuildable candidate — born old enough to clear the age bound the test pins.
     *
     * @param string $name The unique tree name (each scenario needs a distinct tree).
     *
     * @return Tree The imported tree.
     */
    protected function ottoTree(string $name): Tree
    {
        $body = $this->indi('I1', 'Otto', '17 MAR 1930')
            . $this->indi('I2', 'Berta', '03 JUN 1928')
            . $this->indi('I3', 'Cesar', '21 NOV 1925');

        return $this->importFixtureTree($this->gedcom($body), $name);
    }

    /**
     * Import a tree of {@see $count} old "Searchable" individuals (I1..I{count}), every one an
     * eligible candidate with no death date — so a `--limit` below {@see $count} forces the producer
     * to bound the set. Restricted to a single-digit count so the I1..I9 xrefs sort identically under
     * lexicographic and numeric ordering.
     *
     * @param string $name  The unique tree name.
     * @param int    $count The number of eligible individuals (1..9).
     *
     * @return Tree The imported tree.
     */
    protected function searchableTree(string $name, int $count): Tree
    {
        $body = '';

        for ($n = 1; $n <= $count; ++$n) {
            $body .= $this->indi('I' . $n, 'Person' . $n, '17 MAR 1930');
        }

        return $this->importFixtureTree($this->gedcom($body), $name);
    }

    /**
     * Wrap a sequence of INDI records in the shared GEDCOM header + trailer, so the fixture-tree
     * builders ({@see ottoTree()}, {@see searchableTree()}) do not each repeat the boilerplate.
     *
     * @param string $body The concatenated INDI records.
     *
     * @return string The full GEDCOM document.
     */
    private function gedcom(string $body): string
    {
        return "0 HEAD\n"
            . "1 SOUR obituary-matcher-tests\n"
            . "1 GEDC\n"
            . "2 VERS 5.5.1\n"
            . "1 CHAR UTF-8\n"
            . $body
            . "0 TRLR\n";
    }

    /**
     * Build one INDI record for a "Searchable" individual with a birth date and no death date.
     *
     * @param string $xref  The xref (e.g. "I1").
     * @param string $given The given name.
     * @param string $birth The GEDCOM birth date (e.g. "17 MAR 1930").
     *
     * @return string The GEDCOM INDI record.
     */
    private function indi(string $xref, string $given, string $birth): string
    {
        return '0 @' . $xref . "@ INDI\n"
            . '1 NAME ' . $given . " /Searchable/\n"
            . '2 GIVN ' . $given . "\n"
            . "2 SURN Searchable\n"
            . "1 SEX M\n"
            . "1 BIRT\n"
            . '2 DATE ' . $birth . "\n";
    }
}
