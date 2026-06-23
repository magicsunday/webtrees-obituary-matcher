<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\DrainService;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function count;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Drives {@see DrainService::drain()} end-to-end against a real imported tree and a real on-disk
 * file-drop queue. Each scenario asserts a discriminating triple — the summary counter, the queue
 * end-state of the job, and the resulting store delta — so a regression that merely "throws no
 * exception" cannot pass.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DrainService::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\DrainSummary::class)]
#[UsesClass(CandidateRepository::class)]
#[UsesClass(MatchStoreFactory::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\PersonCandidateAdapter::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper::class)]
#[UsesClass(IngestService::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Matching\IngestResult::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Matching\StoredMatch::class)]
#[UsesClass(QueueClient::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(FeederRequestReader::class)]
#[UsesClass(ResponseReader::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(EnrichedMatchEngine::class)]
#[UsesClass(Classifier::class)]
final class DrainServiceTest extends IntegrationTestCase
{
    /**
     * @var string The throwaway queue root this test enqueues into and the drain reads from.
     */
    private string $queueRoot;

    /**
     * @var string The throwaway per-tree match-store base directory, isolated from the live data dir.
     */
    private string $storeRoot;

    /**
     * Create the throwaway queue root and lay out its seven state directories.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir() . '/obituary-drain-' . uniqid('', true);

        $this->queueRoot = $root . '/queue';
        $this->storeRoot = $root . '/store';

        mkdir($this->queueRoot, 0o700, true);
        mkdir($this->storeRoot, 0o700, true);

        (new QueuePaths($this->queueRoot))->ensureLayout();
    }

    /**
     * Remove the throwaway queue root.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeRecursively($this->queueRoot);
        $this->removeRecursively($this->storeRoot);

        parent::tearDown();
    }

    /**
     * A fixture done job is claimed, ingested into the matching tree's store and finalised: the
     * summary counts one ingested job with a stored row, the job lands under ingested/, and the
     * persisted row carries the harvested cemetery fact.
     */
    #[Test]
    public function aDoneJobBecomesAnIngestedStoreRowCarryingTheCemeteryFact(): void
    {
        $tree = $this->ottoTree('fixture-a');
        $job  = $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->skipped);
        self::assertSame(0, $summary->failed);
        self::assertGreaterThanOrEqual(1, $summary->stored);

        // Queue end-state: the job finalised to ingested/, not left in done/ or ingesting/.
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        // Store delta: exactly the persisted suggestion, and the harvested cemetery survived.
        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);

        $facts = $pending[0]->match['extractedFacts'];
        self::assertArrayHasKey('cemetery', $facts);
        self::assertSame('Waldfriedhof Musterstadt', $facts['cemetery']);
    }

    /**
     * Re-running the drain after the job has already been ingested is a no-op: there is no claimable
     * done job left, so nothing is ingested and the store row count is unchanged.
     */
    #[Test]
    public function reRunningTheDrainIsANoOp(): void
    {
        $tree = $this->ottoTree('fixture-a');
        $job  = $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        $this->drainService()->drain(null, 20);
        $countAfterFirst = $this->storeFor($tree)->allPending();

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(0, $summary->stored);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        // Store delta: the second run added no row.
        self::assertCount(count($countAfterFirst), $this->storeFor($tree)->allPending());
    }

    /**
     * A requested person who is no longer a held candidate (an unknown xref) is skipped without
     * aborting the job: the job still finalises to ingested/, and only the other person's notice is
     * stored.
     */
    #[Test]
    public function aMissingCandidateIsSkippedWhileTheOtherPersonIsStored(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // The request names the real I1 plus the non-existent I99 (no held candidate this run).
        $job = $this->seedDoneJobWithTwoPersons(
            'job-001',
            $tree->id(),
            'I1',
            'Otto Searchable',
            'I99',
            'Ghost Person',
        );

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->failed);

        // stored reflects ONLY the held I1 — the ghost person's notice persisted nothing.
        self::assertSame(1, $summary->stored);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);
        self::assertSame('I1', $pending[0]->personId);
    }

    /**
     * A tree-scoped drain whose filter does not match the job's tree releases the job back to done
     * and counts it skipped, persisting no row.
     */
    #[Test]
    public function aTreeFilterMismatchReleasesTheJobBackToDone(): void
    {
        $tree = $this->ottoTree('fixture-a');
        $job  = $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        // Drain a DIFFERENT tree id than the job carries. Only one tree is imported, so id+1
        // resolves no tree-filter target and the job is foreign — released back to done.
        $summary = $this->drainService()->drain($tree->id() + 1, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(1, $summary->skipped);
        self::assertSame(0, $summary->failed);
        self::assertSame(0, $summary->stored);

        // Queue end-state: the job is back in done/ for another run.
        self::assertSame(JobState::Done, $this->paths()->stateOf($job));

        // Store delta: nothing was persisted.
        self::assertSame([], $this->storeFor($tree)->allPending());
    }

    /**
     * Two jobs for two DIFFERENT trees in one run each persist to their own tree-scoped store,
     * proving the per-job store isolation (a shared store would land both rows in one place).
     */
    #[Test]
    public function twoJobsForDifferentTreesEachPersistToTheirOwnStore(): void
    {
        $treeA = $this->ottoTree('fixture-a');
        $treeB = $this->ottoTree('fixture-b');

        $this->seedDoneJob('job-001', $treeA->id(), 'I1', 'Otto Searchable');
        $this->seedDoneJob('job-002', $treeB->id(), 'I1', 'Otto Searchable');

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(2, $summary->ingested);
        self::assertSame(2, $summary->stored);

        // Each tree's own store carries exactly its own job's row, identified by the job-tagged
        // notice URL — a shared store (or a cross-store leak swapping the rows) would fail this,
        // which a bare count would not catch.
        $pendingA = $this->storeFor($treeA)->allPending();
        $pendingB = $this->storeFor($treeB)->allPending();

        self::assertCount(1, $pendingA);
        self::assertCount(1, $pendingB);
        self::assertSame('https://example.test/job-001', $pendingA[0]->obituaryUrl);
        self::assertSame('https://example.test/job-002', $pendingB[0]->obituaryUrl);
    }

    /**
     * A done job whose request.json is schema-invalid is parked in failed-ingest, counted failed,
     * and persists no row.
     */
    #[Test]
    public function aSchemaInvalidRequestIsParkedInFailedIngest(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // Seed a done job whose request.json carries the wrong schema version.
        $jobDir = $this->paths()->doneDir('job-001');
        mkdir($jobDir, 0o700, true);
        AtomicFile::writeJson(
            $jobDir . '/request.json',
            [
                'schemaVersion' => 999,
                'jobId'         => 'job-001',
                'treeId'        => $tree->id(),
                'candidates'    => [['personId' => 'I1']],
            ],
        );

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(1, $summary->failed);
        self::assertSame(0, $summary->stored);

        self::assertSame(JobState::FailedIngest, $this->paths()->stateOf('job-001'));
        self::assertSame([], $this->storeFor($tree)->allPending());
    }

    /**
     * A job a previous run crashed mid-ingest (a pre-placed ingesting/ directory) is NOT re-ingested
     * by this run; it is counted as stale and left in place for a future drain.
     */
    #[Test]
    public function aStaleIngestingJobIsCountedAndLeftInPlace(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // job-001 is a fresh done job; job-002 is a stale ingesting/ directory left by a crash.
        $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        $staleDir = $this->paths()->ingestingDir('job-002');
        mkdir($staleDir, 0o700, true);
        AtomicFile::writeJson(
            $staleDir . '/request.json',
            [
                'schemaVersion' => 2,
                'jobId'         => 'job-002',
                'treeId'        => $tree->id(),
                'candidates'    => [['personId' => 'I1']],
            ],
        );

        $summary = $this->drainService()->drain(null, 20);

        // The fresh job ingested; the stale one was never touched (and not double-counted as failed).
        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->failed);
        self::assertSame(1, $summary->stale);

        // The FRESH job finalised — proving the drain processed job-001, not the stale job-002.
        self::assertSame(JobState::Ingested, $this->paths()->stateOf('job-001'));
        self::assertCount(1, $this->storeFor($tree)->allPending());

        // The stale job is still sitting in ingesting/, untouched.
        self::assertSame(JobState::Ingesting, $this->paths()->stateOf('job-002'));
    }

    /**
     * Build the {@see DrainService} through the SAME dependency wiring the CLI entry point assembles,
     * so the test drives the real composition rather than a hand-rolled stand-in. The only seam used
     * is {@see DrainService::storeForTree()}, redirected to a per-tree store under this test's
     * throwaway root so the assertions read an isolated store rather than the live data dir.
     *
     * @return DrainService
     */
    private function drainService(): DrainService
    {
        $paths    = $this->paths();
        $storeDir = $this->storeRoot;

        return new class($paths, new QueueClient($paths), new FeederRequestReader($paths, 1_048_576), new CandidateRepository(), new IngestService(new ResponseReader($paths), new EnrichedMatchEngine(), new Classifier()), new TreeService(new GedcomImportService()), $storeDir) extends DrainService {
            /**
             * @param QueuePaths          $paths       The queue path builder.
             * @param QueueClient         $client      The queue state-machine driver.
             * @param FeederRequestReader $reader      The validating request reader.
             * @param CandidateRepository $repository  The candidate repository.
             * @param IngestService       $ingest      The enriched ingest pipeline.
             * @param TreeService         $treeService The tree lookup.
             * @param string              $storeRoot   The isolated per-tree store base directory.
             */
            public function __construct(
                QueuePaths $paths,
                QueueClient $client,
                FeederRequestReader $reader,
                CandidateRepository $repository,
                IngestService $ingest,
                TreeService $treeService,
                private readonly string $storeRoot,
            ) {
                parent::__construct($paths, $client, $reader, $repository, $ingest, $treeService);
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
                return new FileMatchStore(
                    MatchStoreFactory::pathForTree($this->storeRoot, $tree)
                );
            }
        };
    }

    /**
     * The queue path builder rooted at this test's throwaway queue.
     *
     * @return QueuePaths
     */
    private function paths(): QueuePaths
    {
        return new QueuePaths($this->queueRoot);
    }

    /**
     * The tree-scoped match store for the given tree, read through the same factory the drain uses.
     *
     * @param Tree $tree The tree whose store is read.
     *
     * @return MatchStore
     */
    private function storeFor(Tree $tree): MatchStore
    {
        return new FileMatchStore(
            MatchStoreFactory::pathForTree($this->storeRoot, $tree)
        );
    }

    /**
     * Import a one-person tree: an old "Otto Searchable" with no death date (so the candidate is
     * rebuildable) born exactly on the date the seeded notice carries.
     *
     * @param string $name The unique tree name (each scenario needs a distinct tree).
     *
     * @return Tree The imported tree.
     */
    private function ottoTree(string $name): Tree
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
     * Seed a single-person done job: a request.json (v2) for the given person plus a response.json
     * (v1) carrying one matching notice with an exact death date and a cemetery.
     *
     * @param string $jobId      The job identifier (also the directory name).
     * @param int    $treeId     The tree the request belongs to.
     * @param string $personId   The requested person id.
     * @param string $noticeName The display name on the seeded notice.
     *
     * @return string The seeded job id.
     */
    private function seedDoneJob(string $jobId, int $treeId, string $personId, string $noticeName): string
    {
        $jobDir = $this->paths()->doneDir($jobId);
        mkdir($jobDir, 0o700, true);

        AtomicFile::writeJson(
            $jobDir . '/request.json',
            [
                'schemaVersion' => 2,
                'jobId'         => $jobId,
                'treeId'        => $treeId,
                'candidates'    => [['personId' => $personId]],
            ],
        );

        AtomicFile::writeJson(
            $jobDir . '/response.json',
            [
                'schemaVersion' => 1,
                'jobId'         => $jobId,
                'results'       => [
                    $personId => [$this->notice($noticeName, 'https://example.test/' . $jobId)],
                ],
            ],
        );

        return $jobId;
    }

    /**
     * Seed a two-person done job: one notice per person, so a missing held candidate can be proven
     * to skip without aborting the held one.
     *
     * @param string $jobId   The job identifier.
     * @param int    $treeId  The tree the request belongs to.
     * @param string $personA The first requested person id (the held one).
     * @param string $nameA   The display name on the first person's notice.
     * @param string $personB The second requested person id (the missing one).
     * @param string $nameB   The display name on the second person's notice.
     *
     * @return string The seeded job id.
     */
    private function seedDoneJobWithTwoPersons(
        string $jobId,
        int $treeId,
        string $personA,
        string $nameA,
        string $personB,
        string $nameB,
    ): string {
        $jobDir = $this->paths()->doneDir($jobId);
        mkdir($jobDir, 0o700, true);

        AtomicFile::writeJson(
            $jobDir . '/request.json',
            [
                'schemaVersion' => 2,
                'jobId'         => $jobId,
                'treeId'        => $treeId,
                'candidates'    => [['personId' => $personA], ['personId' => $personB]],
            ],
        );

        AtomicFile::writeJson(
            $jobDir . '/response.json',
            [
                'schemaVersion' => 1,
                'jobId'         => $jobId,
                'results'       => [
                    $personA => [$this->notice($nameA, 'https://example.test/' . $jobId . '-a')],
                    $personB => [$this->notice($nameB, 'https://example.test/' . $jobId . '-b')],
                ],
            ],
        );

        return $jobId;
    }

    /**
     * Build one untrusted-shape notice the {@see ResponseReader} decodes into a death notice: a name,
     * an exact birth + death date, and a cemetery so the harvest has a fact to carry.
     *
     * @param string $name The display name.
     * @param string $url  The notice URL.
     *
     * @return array<string, mixed> The notice payload.
     */
    private function notice(string $name, string $url): array
    {
        return [
            'noticeType' => 'obituary',
            'name'       => $name,
            'birth'      => '17.03.1930',
            'death'      => '04.09.2023',
            'cemetery'   => 'Waldfriedhof Musterstadt',
            'url'        => $url,
            'source'     => 'example.test',
            'fetchedAt'  => '2026-06-23T10:00:00Z',
        ];
    }

    /**
     * Recursively remove a directory tree.
     *
     * @param string $directory The directory to remove.
     *
     * @return void
     */
    private function removeRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            if (
                ($entry === '.')
                || ($entry === '..')
            ) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeRecursively($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
