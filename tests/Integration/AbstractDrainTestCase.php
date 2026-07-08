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
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Domain\ValidatedResponse;
use MagicSunday\ObituaryMatcher\Matching\CoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileCoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\FileNegativeMemoryStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Matching\IngestServiceFactory;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\NegativeMemoryStore;
use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\DrainService;
use MagicSunday\ObituaryMatcher\Webtrees\DrainSummary;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;

/**
 * Shared harness for the drain integration tests: it wires the {@see DrainService} through the SAME
 * dependency graph the `tools/drain.php` CLI composition root assembles (the only seam redirected is
 * {@see DrainService::storeForTree()}, pointed at an isolated per-tree store under this test's throwaway
 * root so the assertions never touch the live webtrees data dir), and builds the completed-job value
 * objects the transport yields.
 *
 * The drain is transport-neutral, so the scenarios drive it through a {@see RecordingJobTransport}
 * double rather than an on-disk queue: each test seeds the completed outcomes the drain pulls and then
 * asserts the summary counters, the transport-recorded finalisation (ingested / failed / released) and
 * the resulting store delta — a discriminating triple a bare "throws no exception" cannot pass.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractDrainTestCase extends AbstractStoreTestCase
{
    /**
     * The fixed instant the drain double's clock is pinned to (see the anonymous class' now()).
     */
    protected const string PINNED_NOW = '2026-06-23T10:15:30+00:00';

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    protected function tempDirPrefix(): string
    {
        return 'obituary-drain-';
    }

    /**
     * Build the {@see DrainService} through the SAME dependency wiring the `tools/drain.php` CLI entry
     * point assembles, driven by the given transport, so the test drives the real composition root
     * rather than a hand-rolled or mocked stand-in. The only seam used is
     * {@see DrainService::storeForTree()}, redirected to an isolated per-tree store under this test's
     * throwaway root so the assertions read that store rather than the live webtrees data dir.
     *
     * When $storeOverride is non-null the per-tree store seam yields exactly that store instead of the
     * isolated file store, so a scenario can drive a store whose persist step throws — exercising the
     * ingest-throw branch the file store never triggers.
     *
     * @param JobTransport    $transport     The transport that yields and finalises the completed jobs.
     * @param MatchStore|null $storeOverride The store every {@see DrainService::storeForTree()} call
     *                                       returns, or null to use the isolated per-tree file store.
     *
     * @return DrainService
     */
    protected function drainService(JobTransport $transport, ?MatchStore $storeOverride = null): DrainService
    {
        $storeDir = $this->storeRoot;

        return new class(new CandidateRepository(), IngestServiceFactory::create(), new TreeService(new GedcomImportService()), $transport, $storeDir, $storeOverride) extends DrainService {
            /**
             * @param CandidateRepository $repository    The candidate repository.
             * @param IngestService       $ingest        The enriched ingest pipeline.
             * @param TreeService         $treeService   The tree lookup.
             * @param JobTransport        $transport     The job transport.
             * @param string              $storeRoot     The isolated per-tree store base directory.
             * @param MatchStore|null     $storeOverride The store every storeForTree() call returns, or
             *                                           null to build the isolated per-tree file store.
             */
            public function __construct(
                CandidateRepository $repository,
                IngestService $ingest,
                TreeService $treeService,
                JobTransport $transport,
                private readonly string $storeRoot,
                private readonly ?MatchStore $storeOverride,
            ) {
                parent::__construct($repository, $ingest, $treeService, $transport);
            }

            /**
             * Redirect the per-tree store to an isolated directory under the test root, or to the
             * injected override store when one was supplied.
             *
             * @param Tree $tree The tree whose store is requested.
             *
             * @return MatchStore The isolated, tree-scoped store (or the injected override).
             */
            protected function storeForTree(Tree $tree): MatchStore
            {
                return $this->storeOverride ?? new FileMatchStore(
                    MatchStoreFactory::pathForTree($this->storeRoot, $tree)
                );
            }

            /**
             * Redirect the per-tree coverage store to an isolated directory under the test root.
             *
             * @param Tree $tree The tree whose coverage store is requested.
             *
             * @return CoverageStore The isolated, tree-scoped coverage store.
             */
            protected function coverageStoreForTree(Tree $tree): CoverageStore
            {
                return new FileCoverageStore(
                    MatchStoreFactory::pathForTreeId($this->storeRoot . '/coverage', $tree->id())
                );
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
                return new FileNegativeMemoryStore(
                    MatchStoreFactory::pathForTreeId($this->storeRoot . '/negative-memory', $tree->id())
                );
            }

            /**
             * Pin the clock so the recorded negative-memory timestamp is deterministic.
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
     * The tree-scoped negative-memory store for the given tree, read through the same layout the drain
     * uses, so a test can assert what genuine miss the drain recorded.
     *
     * @param Tree $tree The tree whose negative-memory store is read.
     *
     * @return NegativeMemoryStore The isolated, tree-scoped negative-memory store.
     */
    protected function negativeMemoryStoreFor(Tree $tree): NegativeMemoryStore
    {
        return new FileNegativeMemoryStore(
            MatchStoreFactory::pathForTreeId($this->storeRoot . '/negative-memory', $tree->id())
        );
    }

    /**
     * The tree-scoped coverage store for the given tree, read through the same layout the drain uses.
     *
     * @param Tree $tree The tree whose coverage store is read.
     *
     * @return CoverageStore The isolated, tree-scoped coverage store.
     */
    protected function coverageStoreFor(Tree $tree): CoverageStore
    {
        return new FileCoverageStore(
            MatchStoreFactory::pathForTreeId($this->storeRoot . '/coverage', $tree->id())
        );
    }

    /**
     * The tree-scoped match store for the given tree, read through the same factory the drain uses.
     *
     * @param Tree $tree The tree whose store is read.
     *
     * @return MatchStore
     */
    protected function storeFor(Tree $tree): MatchStore
    {
        return new FileMatchStore(
            MatchStoreFactory::pathForTree($this->storeRoot, $tree)
        );
    }

    /**
     * Assert the happy-path outcome shared by the cemetery-fact scenarios: exactly one job ingested
     * with no skip/failure/stale and a single stored row, the job finalised as ingested through the
     * transport, and the persisted row carrying the harvested cemetery fact. Returns the row's
     * harvested facts so the caller can continue with its own scenario-specific assertions.
     *
     * @param DrainSummary          $summary   The summary returned by the drain run under test.
     * @param Tree                  $tree      The tree whose store is read.
     * @param string                $job       The seeded job id expected to finalise.
     * @param RecordingJobTransport $transport The transport the drain finalised the job through.
     *
     * @return array<string, mixed> The harvested facts of the single persisted row.
     */
    protected function assertSingleCemeteryRowFinalised(DrainSummary $summary, Tree $tree, string $job, RecordingJobTransport $transport): array
    {
        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->skipped);
        self::assertSame(0, $summary->failed);
        self::assertSame(1, $summary->stored);
        self::assertSame(0, $summary->stale);

        // Finalisation: the job was marked ingested through the transport, not failed or released.
        self::assertTrue($transport->wasIngested($job));

        // Store delta: exactly the persisted suggestion, and the harvested cemetery survived.
        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);

        $facts = $pending[0]->match['extractedFacts'];
        self::assertArrayHasKey('cemetery', $facts);
        self::assertSame('Waldfriedhof Musterstadt', $facts['cemetery']);

        return $facts;
    }

    /**
     * Import a one-person tree: an old "Otto Searchable" with no death date (so the candidate is
     * rebuildable) born exactly on the date the seeded notice carries.
     *
     * @param string $name The unique tree name (each scenario needs a distinct tree).
     *
     * @return Tree The imported tree.
     */
    protected function ottoTree(string $name): Tree
    {
        $gedcom = "0 HEAD\n"
            . "1 SOUR obituary-matcher-tests\n"
            . "1 GEDC\n"
            . "2 VERS 5.5.1\n"
            . "1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n"
            . "1 NAME Otto /Searchable/\n"
            . "2 GIVN Otto\n"
            . "2 SURN Searchable\n"
            . "1 SEX M\n"
            . "1 BIRT\n"
            . "2 DATE 17 MAR 1930\n"
            . "0 TRLR\n";

        return $this->importFixtureTree($gedcom, $name);
    }

    /**
     * Build a single-person {@see CompletedJob}: one matching notice with an exact death date, a
     * cemetery and an exact funeral date, so the harvest carries both burial facts. The raw notice is
     * narrowed through the SAME {@see ResponseValidator} the transport uses, so the test feeds the drain
     * exactly the validated seam shape it sees in production.
     *
     * @param string $jobId      The job identifier.
     * @param int    $treeId     The tree the request belongs to.
     * @param string $personId   The requested person id.
     * @param string $noticeName The display name on the seeded notice.
     *
     * @return CompletedJob The completed job the transport yields.
     */
    protected function completedJob(string $jobId, int $treeId, string $personId, string $noticeName): CompletedJob
    {
        $validated = $this->validatedResponse(
            $jobId,
            [$personId],
            [$personId => [$this->notice($noticeName, 'https://example.test/' . $jobId)]],
        );

        return new CompletedJob($jobId, $treeId, [$personId], $validated->notices, $validated->coverage);
    }

    /**
     * Build a two-person {@see CompletedJob}: one notice per person, so a missing (or privacy-hidden)
     * held candidate can be proven to skip without aborting the held one.
     *
     * @param string $jobId   The job identifier.
     * @param int    $treeId  The tree the request belongs to.
     * @param string $personA The first requested person id (the held one).
     * @param string $nameA   The display name on the first person's notice.
     * @param string $personB The second requested person id (the missing one).
     * @param string $nameB   The display name on the second person's notice.
     *
     * @return CompletedJob The completed job the transport yields.
     */
    protected function completedJobWithTwoPersons(
        string $jobId,
        int $treeId,
        string $personA,
        string $nameA,
        string $personB,
        string $nameB,
    ): CompletedJob {
        $validated = $this->validatedResponse(
            $jobId,
            [$personA, $personB],
            [
                $personA => [$this->notice($nameA, 'https://example.test/' . $jobId . '-a')],
                $personB => [$this->notice($nameB, 'https://example.test/' . $jobId . '-b')],
            ],
        );

        return new CompletedJob(
            $jobId,
            $treeId,
            [$personA, $personB],
            $validated->notices,
            $validated->coverage,
        );
    }

    /**
     * Narrow a raw finder `results` map through the shared {@see ResponseValidator} into the validated
     * notices shape the {@see CompletedJob} carries.
     *
     * @param string                                    $jobId     The job the response belongs to.
     * @param list<string>                              $personIds The requested person ids (the validator's ownership boundary).
     * @param array<string, list<array<string, mixed>>> $results   The raw per-person notice lists.
     *
     * @return ValidatedResponse The validated notices and per-portal coverage keyed by person id.
     */
    private function validatedResponse(string $jobId, array $personIds, array $results): ValidatedResponse
    {
        // Wrap each raw notice list into the contract PersonResult { notices, coverage } shape the
        // validator now requires; synthesise an all-ok coverage so the drain path sees a real response.
        $wrapped = [];

        foreach ($results as $personId => $notices) {
            $wrapped[$personId] = [
                'notices'  => $notices,
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'ok', 'noticeCount' => count($notices)],
                ],
            ];
        }

        return (new ResponseValidator())->validate(
            [
                'schemaVersion' => 1,
                'jobId'         => $jobId,
                'results'       => $wrapped,
            ],
            $jobId,
            $personIds,
        );
    }

    /**
     * Build one untrusted-shape notice the {@see ResponseValidator} decodes into a death notice: a name,
     * an exact birth + death date, a cemetery and an exact funeral date so the harvest carries both
     * burial facts.
     *
     * @param string $name The display name.
     * @param string $url  The notice URL.
     *
     * @return array<string, mixed> The notice payload.
     */
    protected function notice(string $name, string $url): array
    {
        return [
            'noticeType'  => 'obituary',
            'name'        => $name,
            'birth'       => '17.03.1930',
            'death'       => '04.09.2023',
            'cemetery'    => 'Waldfriedhof Musterstadt',
            'funeralDate' => '08.09.2023',
            'url'         => $url,
            'source'      => 'example.test',
            'fetchedAt'   => '2026-06-23T10:00:00Z',
        ];
    }

    /**
     * Build a completed job carrying NO notices for the person and the given explicit per-portal
     * coverage, so a test can drive the search-outcome branches the all-ok harness cannot synthesise (a
     * genuine miss with 0 notices, or a portal outage).
     *
     * @param string               $jobId    The job identifier.
     * @param int                  $treeId   The tree the request belongs to.
     * @param string               $personId The requested person id.
     * @param list<PortalCoverage> $coverage The explicit per-portal coverage for that person.
     *
     * @return CompletedJob The completed job the transport yields.
     */
    protected function completedJobWithCoverage(string $jobId, int $treeId, string $personId, array $coverage): CompletedJob
    {
        return new CompletedJob(
            $jobId,
            $treeId,
            [$personId],
            [$personId => []],
            [$personId => $coverage],
        );
    }
}
