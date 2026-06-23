<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
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

use function mkdir;

/**
 * Drives {@see DrainService::drain()} end-to-end against a real imported tree and a real on-disk
 * file-drop queue. Each scenario asserts a discriminating triple — the summary counter, the queue
 * end-state of the job, and the resulting store delta — so a regression that merely "throws no
 * exception" cannot pass. The throwaway queue/store plumbing and the real-graph wiring live in
 * {@see AbstractDrainTestCase}; this class adds the branch-specific scenarios only.
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
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\ResponseValidationException::class)]
final class DrainServiceTest extends AbstractDrainTestCase
{
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

        $this->assertSingleCemeteryRowFinalised(
            $this->drainService()->drain(null, 20),
            $tree,
            $job,
        );
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

        // Anchor the baseline: the first run stored exactly one row, so the no-op assertion
        // below is a real delta-of-zero rather than a trivial pass against an empty store.
        self::assertCount(1, $this->storeFor($tree)->allPending(), 'first run stored exactly one suggestion');

        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(0, $summary->stored);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        // Store delta: the second run added no row.
        self::assertCount(1, $this->storeFor($tree)->allPending(), 'second run added no row');
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

        // The held I1 is stored; the ghost person's notice (an unknown xref) persisted nothing.
        $this->assertOnlyI1Stored($tree, $job);
    }

    /**
     * A requested person the drain's principal may NOT see (a privacy-suppressed but existing
     * confidential individual) is excluded by {@see CandidateRepository::findByXrefs()}'s privacy
     * gate exactly like an unknown xref: the job still finalises, and only the visible person's
     * notice is stored. This exercises the second of findByXrefs' two exclusion sub-paths
     * (`!canShow()`), distinct from the unknown-xref path above, proving the gate discriminates at
     * the drain level — the drain runs without a logged-in user (a CLI/cron visitor), so a
     * confidential record is genuinely invisible to its principal.
     */
    #[Test]
    public function aPrivacySuppressedCandidateIsSkippedWhileTheVisibleOneIsStored(): void
    {
        $tree = $this->ottoTreeWithConfidential('fixture-a');

        // The request names the public, dead I1 plus the confidential, living I7.
        $job = $this->seedDoneJobWithTwoPersons(
            'job-001',
            $tree->id(),
            'I1',
            'Otto Searchable',
            'I7',
            'Ida Private',
        );

        // Positive control: as the seeded admin BOTH records are visible, so the visitor-context
        // skip below is a real privacy discriminator rather than a vacuous pass against an
        // already-invisible record.
        self::assertTrue($this->requireIndividual('I1', $tree)->canShow());
        self::assertTrue($this->requireIndividual('I7', $tree)->canShow());

        // Drop to a visitor — the principal a CLI/cron drain runs as. The confidential I7 is now
        // hidden by the privacy gate, while the dead/public I1 stays visible.
        Auth::logout();

        self::assertTrue($this->requireIndividual('I1', $tree)->canShow());
        self::assertFalse($this->requireIndividual('I7', $tree)->canShow());

        // The visible I1 is stored; the privacy-suppressed I7's notice persisted nothing.
        $this->assertOnlyI1Stored($tree, $job);
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
     * A done job whose response.json is schema-invalid does NOT halt the whole drain: the corrupt job
     * is parked in failed-ingest (counted failed, never left stranded in ingesting/), while a second,
     * valid job in the same batch still reaches ingested/ with its row persisted. This proves the
     * ingest step is isolated per job — one corrupt response.json cannot strand the batch.
     */
    #[Test]
    public function aResponseInvalidJobIsParkedWhileTheValidJobStillIngests(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // job-001 carries a valid request but a schema-invalid response.json (wrong version), so the
        // ingest step's ResponseReader throws ResponseValidationException only once ingest runs.
        $corruptDir = $this->paths()->doneDir('job-001');
        mkdir($corruptDir, 0o700, true);
        AtomicFile::writeJson(
            $corruptDir . '/request.json',
            [
                'schemaVersion' => 2,
                'jobId'         => 'job-001',
                'treeId'        => $tree->id(),
                'candidates'    => [['personId' => 'I1']],
            ],
        );
        AtomicFile::writeJson(
            $corruptDir . '/response.json',
            [
                'schemaVersion' => 999,
                'jobId'         => 'job-001',
                'results'       => [],
            ],
        );

        // job-002 is a fully valid job that must still ingest despite the corrupt sibling.
        $this->seedDoneJob('job-002', $tree->id(), 'I1', 'Otto Searchable');

        $summary = $this->drainService()->drain(null, 20);

        // The corrupt job failed, the valid job ingested — the bad job did NOT halt the batch.
        self::assertSame(1, $summary->ingested);
        self::assertSame(1, $summary->failed);
        self::assertSame(0, $summary->skipped);
        self::assertSame(1, $summary->stored);

        // Queue end-states: the corrupt job parked in failed-ingest (NOT stranded in ingesting/),
        // the valid job finalised under ingested/.
        self::assertSame(JobState::FailedIngest, $this->paths()->stateOf('job-001'));
        self::assertSame(JobState::Ingested, $this->paths()->stateOf('job-002'));

        // Store delta: exactly the valid job's single row was persisted.
        self::assertCount(1, $this->storeFor($tree)->allPending());
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
     * Drain the seeded two-person job and assert the discriminating outcome shared by the
     * exclusion sub-paths: one job ingested with no failure, exactly the visible I1 persisted as
     * the sole row, and the job finalised to ingested/. The OTHER requested person is excluded by
     * {@see CandidateRepository::findByXrefs()} — whether because its xref is unknown or because
     * the drain's principal may not see it — so the assertions are identical, only the setup
     * differs.
     *
     * @param Tree   $tree The tree whose store is read.
     * @param string $job  The seeded job id expected to finalise.
     *
     * @return void
     */
    private function assertOnlyI1Stored(Tree $tree, string $job): void
    {
        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->failed);
        self::assertSame(1, $summary->stored);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));

        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);
        self::assertSame('I1', $pending[0]->personId);
    }

    /**
     * Import a two-person tree for the privacy sub-path: the public, dead I1 from {@see ottoTree()}
     * plus a confidential (`RESN confidential`), still-living I7 whose {@see Individual::canShow()}
     * is true for the seeded admin but false for a visitor. The privacy preferences make the gate
     * actually bite for a visitor.
     *
     * @param string $name The unique tree name.
     *
     * @return Tree The imported tree.
     */
    private function ottoTreeWithConfidential(string $name): Tree
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
            . "0 @I7@ INDI\n"
            . "1 NAME Ida /Private/\n"
            . "2 GIVN Ida\n"
            . "2 SURN Private\n"
            . "1 SEX F\n"
            . "1 RESN confidential\n"
            . "1 BIRT\n"
            . "2 DATE 4 APR 1990\n"
            . "0 TRLR\n";

        $tree = $this->importFixtureTree($gedcom, $name);

        // Make the privacy gate bite for a visitor: hide the dead behind privacy and cap the alive
        // age so the 1990-born I7 is treated as living (and thus confidential), while the 1930-born
        // I1 is dead and public.
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);
        $tree->setPreference('MAX_ALIVE_AGE', '80');

        return $tree;
    }

    /**
     * Materialise an individual by xref, failing the test when the fixture lacks it — so the
     * canShow() controls assert against a real record, never a silent null.
     *
     * @param string $xref The xref to resolve.
     * @param Tree   $tree The tree the xref belongs to.
     *
     * @return Individual The resolved individual.
     */
    private function requireIndividual(string $xref, Tree $tree): Individual
    {
        $individual = Registry::individualFactory()->make($xref, $tree);

        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
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
}
