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
use MagicSunday\ObituaryMatcher\Webtrees\DrainSummary;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;

use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Shared harness for the drain integration tests: it lays out a throwaway file-drop queue and an
 * isolated per-tree match-store root, wires the {@see DrainService} through the SAME dependency graph
 * the {@see \tools/drain.php} CLI composition root assembles (the only seam redirected is
 * {@see DrainService::storeForTree()}, pointed at the throwaway store so the assertions never touch
 * the live webtrees data dir), and seeds fixture done jobs and one-person trees.
 *
 * Both {@see DrainServiceTest} (which pins the individual drain branches) and {@see DrainEndToEndTest}
 * (which pins the whole vertical once) extend this base so the queue/store plumbing and the real-graph
 * wiring live in exactly one place.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractDrainTestCase extends IntegrationTestCase
{
    /**
     * @var string The throwaway queue root this test enqueues into and the drain reads from.
     */
    protected string $queueRoot;

    /**
     * @var string The throwaway per-tree match-store base directory, isolated from the live data dir.
     */
    protected string $storeRoot;

    /**
     * Create the throwaway queue root and lay out its state directories.
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
     * Remove the throwaway roots.
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
     * Build the {@see DrainService} through the SAME dependency wiring the `tools/drain.php` CLI entry
     * point assembles, so the test drives the real composition root rather than a hand-rolled or
     * mocked stand-in. The only seam used is {@see DrainService::storeForTree()}, redirected to an
     * isolated per-tree store under this test's throwaway root so the assertions read that store
     * rather than the live webtrees data dir.
     *
     * @return DrainService
     */
    protected function drainService(): DrainService
    {
        $paths    = $this->paths();
        $storeDir = $this->storeRoot;

        return new class($paths, new QueueClient($paths), new FeederRequestReader($paths, 5_242_880), new CandidateRepository(), new IngestService(new ResponseReader($paths), new EnrichedMatchEngine(), new Classifier()), new TreeService(new GedcomImportService()), $storeDir) extends DrainService {
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
    protected function paths(): QueuePaths
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
    protected function storeFor(Tree $tree): MatchStore
    {
        return new FileMatchStore(
            MatchStoreFactory::pathForTree($this->storeRoot, $tree)
        );
    }

    /**
     * Assert the happy-path outcome shared by the cemetery-fact scenarios: exactly one job ingested
     * with no skip/failure/stale and a single stored row, the job finalised under ingested/, and the
     * persisted row carrying the harvested cemetery fact. Returns the row's harvested facts so the
     * caller can continue with its own scenario-specific assertions (the funeral date, a re-run).
     *
     * @param DrainSummary $summary The summary returned by the drain run under test.
     * @param Tree         $tree    The tree whose store is read.
     * @param string       $job     The seeded job id expected to finalise.
     *
     * @return array<string, mixed> The harvested facts of the single persisted row.
     */
    protected function assertSingleCemeteryRowFinalised(DrainSummary $summary, Tree $tree, string $job): array
    {
        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->skipped);
        self::assertSame(0, $summary->failed);
        self::assertSame(1, $summary->stored);
        self::assertSame(0, $summary->stale);

        // Queue end-state: the job finalised under ingested/, not left in done/ or ingesting/.
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        // Store delta: exactly the persisted suggestion, and the harvested cemetery survived.
        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);

        $facts = $pending[0]->match['extractedFacts'];
        self::assertArrayHasKey('cemetery', $facts);
        self::assertSame('Waldfriedhof Musterstadt', $facts['cemetery']);

        return $facts;
    }

    /**
     * Assert the per-job isolation contract shared by the "one bad job, one valid job" scenarios:
     * after seeding a valid sibling (job-002) and draining the whole batch, the bad job ($badJobId)
     * is parked in failed-ingest (counted failed, never stranded in ingesting/), while the valid job
     * still reaches ingested/ with its single row persisted. The caller seeds the bad job in its own
     * scenario-specific shape (a schema-invalid response, an oversize request, …) before calling this.
     *
     * @param Tree   $tree     The tree both jobs belong to.
     * @param string $badJobId The seeded bad job expected to park in failed-ingest.
     *
     * @return void
     */
    protected function assertBadJobParkedWhileValidJobIngests(Tree $tree, string $badJobId): void
    {
        // job-002 is a fully valid job that must still ingest despite the bad sibling.
        $this->seedDoneJob('job-002', $tree->id(), 'I1', 'Otto Searchable');

        $summary = $this->drainService()->drain(null, 20);

        // The bad job failed, the valid job ingested — the bad job did NOT halt the batch.
        self::assertSame(1, $summary->ingested);
        self::assertSame(1, $summary->failed);
        self::assertSame(0, $summary->skipped);
        self::assertSame(1, $summary->stored);

        // Queue end-states: the bad job parked in failed-ingest (NOT stranded in ingesting/), the
        // valid job finalised under ingested/.
        self::assertSame(JobState::FailedIngest, $this->paths()->stateOf($badJobId));
        self::assertSame(JobState::Ingested, $this->paths()->stateOf('job-002'));

        // Store delta: exactly the valid job's single row was persisted.
        self::assertCount(1, $this->storeFor($tree)->allPending());
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
     * Seed a single-person done job: a request.json (schema v2, carrying treeId) for the given person
     * plus a response.json (schema v1) carrying one matching notice with an exact death date, a
     * cemetery and an exact funeral date, so the harvest carries both burial facts.
     *
     * @param string $jobId      The job identifier (also the directory name).
     * @param int    $treeId     The tree the request belongs to.
     * @param string $personId   The requested person id.
     * @param string $noticeName The display name on the seeded notice.
     *
     * @return string The seeded job id.
     */
    protected function seedDoneJob(string $jobId, int $treeId, string $personId, string $noticeName): string
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
     * Build one untrusted-shape notice the {@see ResponseReader} decodes into a death notice: a name,
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
     * Recursively remove a directory tree.
     *
     * @param string $directory The directory to remove.
     *
     * @return void
     */
    protected function removeRecursively(string $directory): void
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

            // A symlink to a directory reports is_dir() === true; recursing into it would delete the
            // LINK TARGET's contents outside the temp dir. Unlink the link itself instead of traversing.
            if (
                is_dir($path)
                && !is_link($path)
            ) {
                $this->removeRecursively($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
