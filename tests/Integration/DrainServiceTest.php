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
use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Matching\FileCoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\FailureCategory;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\DrainService;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Drives {@see DrainService::drain()} end-to-end against a real imported tree and a
 * {@see RecordingJobTransport} double. Each scenario asserts a discriminating triple — the summary
 * counter, the transport-recorded finalisation of the job (ingested / failed / released), and the
 * resulting store delta — so a regression that merely "throws no exception" cannot pass. The
 * throwaway store plumbing and the real-graph wiring live in {@see AbstractDrainTestCase}; this class
 * adds the branch-specific scenarios only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DrainService::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\DrainSummary::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\DrainOutcome::class)]
#[UsesClass(CandidateRepository::class)]
#[UsesClass(MatchStoreFactory::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\CoverageStoreFactory::class)]
#[UsesClass(FileCoverageStore::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\PersonCandidateAdapter::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper::class)]
#[UsesClass(IngestService::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Matching\IngestResult::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\CompletedJob::class)]
#[UsesClass(FailedJob::class)]
#[UsesClass(FailureCategory::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\ResponseValidator::class)]
#[UsesClass(EnrichedMatchEngine::class)]
#[UsesClass(Classifier::class)]
final class DrainServiceTest extends AbstractDrainTestCase
{
    /**
     * A completed job is ingested into the matching tree's store and finalised: the summary counts one
     * ingested job with a stored row, the transport records the ingest finalisation, and the persisted
     * row carries the harvested cemetery fact.
     */
    #[Test]
    public function aCompletedJobBecomesAnIngestedStoreRowCarryingTheCemeteryFact(): void
    {
        $tree      = $this->ottoTree('fixture-a');
        $transport = new RecordingJobTransport([$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')]);

        $this->assertSingleCemeteryRowFinalised(
            $this->drainService($transport)->drain(null, 20),
            $tree,
            'job-001',
            $transport,
        );
    }

    /**
     * The drain persists each requested person's per-portal coverage to the coverage store, so a later
     * render can tell a genuine miss from a portal outage. The completed job carries the all-ok coverage
     * the harness synthesises for I1.
     *
     * @return void
     */
    #[Test]
    public function theDrainRecordsEachRequestedPersonsCoverage(): void
    {
        $tree      = $this->ottoTree('fixture-coverage');
        $transport = new RecordingJobTransport([$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')]);

        $this->drainService($transport)->drain(null, 20);

        $coverage = $this->coverageStoreFor($tree)->findByPerson('I1');

        self::assertCount(1, $coverage);
        self::assertSame('trauer_anzeigen', $coverage[0]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[0]->status);
    }

    /**
     * A drain that pulls no new completed jobs is a no-op: after a first run stored exactly one row, a
     * second run over an empty transport ingests nothing and the store row count is unchanged.
     */
    #[Test]
    public function aReDrainWithNoNewCompletedJobsIsANoOp(): void
    {
        $tree = $this->ottoTree('fixture-a');

        $this->drainService(
            new RecordingJobTransport([$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')])
        )->drain(null, 20);

        // Anchor the baseline: the first run stored exactly one row, so the no-op assertion below is a
        // real delta-of-zero rather than a trivial pass against an empty store.
        self::assertCount(1, $this->storeFor($tree)->allPending(), 'first run stored exactly one suggestion');

        // A second run with nothing left to pull.
        $summary = $this->drainService(new RecordingJobTransport())->drain(null, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(0, $summary->stored);

        // Store delta: the second run added no row.
        self::assertCount(1, $this->storeFor($tree)->allPending(), 'second run added no row');
    }

    /**
     * A requested person who is no longer a held candidate (an unknown xref) is skipped without
     * aborting the job: the job still finalises as ingested, and only the other person's notice is
     * stored.
     */
    #[Test]
    public function aMissingCandidateIsSkippedWhileTheOtherPersonIsStored(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // The request names the real I1 plus the non-existent I99 (no held candidate this run).
        $transport = new RecordingJobTransport([
            $this->completedJobWithTwoPersons('job-001', $tree->id(), 'I1', 'Otto Searchable', 'I99', 'Ghost Person'),
        ]);

        // The held I1 is stored; the ghost person's notice (an unknown xref) persisted nothing.
        $this->assertOnlyI1Stored($tree, 'job-001', $transport);
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
        $transport = new RecordingJobTransport([
            $this->completedJobWithTwoPersons('job-001', $tree->id(), 'I1', 'Otto Searchable', 'I7', 'Ida Private'),
        ]);

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
        $this->assertOnlyI1Stored($tree, 'job-001', $transport);
    }

    /**
     * A tree-scoped drain whose filter does not match the job's tree releases the job back to the
     * completed pool through the transport and counts it skipped, persisting no row.
     */
    #[Test]
    public function aTreeFilterMismatchReleasesTheJobBackToTheCompletedPool(): void
    {
        $tree      = $this->ottoTree('fixture-a');
        $transport = new RecordingJobTransport([$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')]);

        // Drain a DIFFERENT tree id than the job carries. Only one tree is imported, so id+1 resolves no
        // tree-filter target and the job is foreign — released back to the pool.
        $summary = $this->drainService($transport)->drain($tree->id() + 1, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(1, $summary->skipped);
        self::assertSame(0, $summary->failed);
        self::assertSame(0, $summary->stored);

        // Finalisation: the job was released for another run, neither ingested nor failed.
        self::assertTrue($transport->wasReleased('job-001'));
        self::assertFalse($transport->wasIngested('job-001'));

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

        $transport = new RecordingJobTransport([
            $this->completedJob('job-001', $treeA->id(), 'I1', 'Otto Searchable'),
            $this->completedJob('job-002', $treeB->id(), 'I1', 'Otto Searchable'),
        ]);

        $summary = $this->drainService($transport)->drain(null, 20);

        self::assertSame(2, $summary->ingested);
        self::assertSame(2, $summary->stored);

        // Each tree's own store carries exactly its own job's row, identified by the job-tagged notice
        // URL — a shared store (or a cross-store leak swapping the rows) would fail this, which a bare
        // count would not catch.
        $pendingA = $this->storeFor($treeA)->allPending();
        $pendingB = $this->storeFor($treeB)->allPending();

        self::assertCount(1, $pendingA);
        self::assertCount(1, $pendingB);
        self::assertSame('https://example.test/job-001', $pendingA[0]->obituaryUrl);
        self::assertSame('https://example.test/job-002', $pendingB[0]->obituaryUrl);
    }

    /**
     * A throw from the ingest STEP itself terminally parks the job as ingest_failed rather than
     * releasing it: a job whose {@see IngestService::ingest()} throws mid-flight — here because the
     * per-tree store's persist step fails — must not be re-processed every drain (head-of-line
     * starvation), so {@see DrainService} catches the {@see \Throwable} and finalises the job as failed.
     * This pins {@see DrainService::ingestCompleted()}'s catch arm directly: a completed job reaches
     * ingest, the store throws on upsert, and the job is failed with reason `ingest_failed` and nothing
     * stored.
     */
    #[Test]
    public function anIngestThrowParksTheJobAsIngestFailed(): void
    {
        $tree      = $this->ottoTree('fixture-a');
        $transport = new RecordingJobTransport([$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')]);

        // A store whose persist step deterministically throws: ingest() scores the held I1's notice and
        // calls upsertPending(), whose RuntimeException propagates out of ingest() into ingestCompleted's
        // catch(Throwable) arm. The other methods are unused by this scenario.
        $throwingStore = new class implements MatchStore {
            /**
             * Throws to simulate the persistence layer failing mid-ingest.
             *
             * @param StoredMatch $match The suggestion the ingest tried to persist.
             *
             * @return bool Never returns; always throws in this fault double.
             */
            public function upsertPending(StoredMatch $match): bool
            {
                throw new RuntimeException('persist failed');
            }

            /**
             * Unused by this scenario; returns no rows.
             *
             * @param string $personId The candidate identifier.
             *
             * @return list<StoredMatch> The empty result.
             */
            public function findByPerson(string $personId): array
            {
                return [];
            }

            /**
             * Unused by this scenario; returns no pending rows.
             *
             * @return list<StoredMatch> The empty result.
             */
            public function allPending(): array
            {
                return [];
            }

            /**
             * Unused by this scenario; returns no rows.
             *
             * @return list<StoredMatch> The empty result.
             */
            public function all(): array
            {
                return [];
            }

            /**
             * Unused by this scenario; resolves no row.
             *
             * @param string $personId The candidate identifier.
             * @param string $rowKey   The canonical row key.
             *
             * @return StoredMatch|null Always null.
             */
            public function findOne(string $personId, string $rowKey): ?StoredMatch
            {
                return null;
            }

            /**
             * Unused by this scenario; accepts the call as a no-op.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source URL.
             * @param string|null $reason      The rejection reason.
             *
             * @return void
             */
            public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Unused by this scenario; accepts the call as a no-op.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source URL.
             * @param string|null $reason      The reviewer note.
             *
             * @return void
             */
            public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Unused by this scenario; reports no transition.
             *
             * @param string    $personId    The candidate identifier.
             * @param string    $obituaryUrl The source URL.
             * @param WriteBack $writeBack   The write-back IDs.
             *
             * @return bool Always false.
             */
            public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
            {
                return false;
            }

            /**
             * Unused by this scenario; accepts the call as a no-op.
             *
             * @param string $personId    The candidate identifier.
             * @param string $obituaryUrl The source URL.
             *
             * @return void
             */
            public function revert(string $personId, string $obituaryUrl): void
            {
            }
        };

        $summary = $this->drainService($transport, $throwingStore)->drain(null, 20);

        // The job failed at the ingest step, persisted nothing, and was counted failed (not released).
        self::assertSame(0, $summary->ingested);
        self::assertSame(0, $summary->stored);
        self::assertSame(1, $summary->failed);

        // Finalisation: the job was parked under the ingest_failed category, NOT released.
        self::assertSame(FailureCategory::IngestFailed, $transport->failureReason('job-001'));
        self::assertFalse($transport->wasReleased('job-001'));

        // Store delta: the real per-tree store (separate from the throwing double) holds no row.
        self::assertSame([], $this->storeFor($tree)->allPending());
    }

    /**
     * A failure of the PARK itself (markFailed throwing) must NOT abort the whole drain. Two jobs are
     * yielded by a transport whose markFailed always throws; the drain must still process BOTH
     * (counting them failed) rather than crashing on the first and starving the second — the
     * head-of-line starvation the parkFailed() guard prevents. Without the guard the first markFailed
     * throw propagates out of the drain loop and the second job is never seen.
     *
     * @return void
     */
    #[Test]
    public function aParkFailureDoesNotAbortTheDrainOrStarveTheNextJob(): void
    {
        $transport = new class implements JobTransport {
            /**
             * {@inheritDoc}
             */
            public function submit(FinderRequest $request): string
            {
                return $request->jobId;
            }

            /**
             * {@inheritDoc}
             */
            public function fetchCompleted(): iterable
            {
                yield new FailedJob('job-a', 1, ['I1'], FailureCategory::FinderFailed);
                yield new FailedJob('job-b', 1, ['I1'], FailureCategory::FinderFailed);
            }

            /**
             * {@inheritDoc}
             */
            public function markIngested(string $jobId, array $counts, array $warnings = []): void
            {
            }

            /**
             * {@inheritDoc}
             */
            public function markFailed(string $jobId, FailureCategory $reasonCategory, array $warnings = []): void
            {
                throw new RuntimeException('park failed');
            }

            /**
             * {@inheritDoc}
             */
            public function release(string $jobId): void
            {
            }

            /**
             * {@inheritDoc}
             */
            public function inFlightRequests(): iterable
            {
                return [];
            }

            /**
             * {@inheritDoc}
             */
            public function staleCount(): int
            {
                return 0;
            }
        };

        $summary = $this->drainService($transport)->drain(null, 20);

        // Both jobs were processed and counted failed: the first job's throwing park did not abort the
        // drain, so the second job behind it was not starved.
        self::assertSame(2, $summary->failed);
        self::assertSame(0, $summary->ingested);
    }

    /**
     * The limit bounds the number of completed jobs processed: with two completed jobs and a cap of 1,
     * only the first is ingested and the second is left for a later run — the break-AFTER loop never
     * advances the transport iterator past the cap.
     */
    #[Test]
    public function theLimitCapsTheNumberOfCompletedJobsProcessed(): void
    {
        $tree = $this->ottoTree('fixture-a');

        $transport = new RecordingJobTransport([
            $this->completedJob('job-1', $tree->id(), 'I1', 'Otto Searchable'),
            $this->completedJob('job-2', $tree->id(), 'I1', 'Otto Searchable'),
        ]);

        $summary = $this->drainService($transport)->drain(null, 1);

        // Exactly one job processed (the cap): the first is ingested, the second untouched.
        self::assertSame(1, $summary->ingested);
        self::assertTrue($transport->wasIngested('job-1'));
        self::assertFalse($transport->wasIngested('job-2'));
        self::assertCount(1, $this->storeFor($tree)->allPending());
    }

    /**
     * The transport's stale tally is surfaced verbatim in the drain summary alongside the ingested
     * jobs, so an operator-facing stale count reflects the transport's own bookkeeping.
     */
    #[Test]
    public function theStaleCountFromTheTransportIsSurfacedInTheSummary(): void
    {
        $tree = $this->ottoTree('fixture-a');

        $transport = new RecordingJobTransport(
            [$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')],
            [],
            3,
        );

        $summary = $this->drainService($transport)->drain(null, 20);

        self::assertSame(1, $summary->ingested);
        self::assertSame(3, $summary->stale);
    }

    /**
     * A non-positive limit processes nothing but still reports the transport's stale tally: the
     * completed iterator is never advanced (no job ingested), and the summary carries the stale count.
     */
    #[Test]
    public function aNonPositiveLimitDrainsNothingButStillReportsStale(): void
    {
        $tree = $this->ottoTree('fixture-a');

        $transport = new RecordingJobTransport(
            [$this->completedJob('job-001', $tree->id(), 'I1', 'Otto Searchable')],
            [],
            2,
        );

        $summary = $this->drainService($transport)->drain(null, 0);

        self::assertSame(0, $summary->ingested);
        self::assertSame(0, $summary->stored);
        self::assertSame(2, $summary->stale);

        // The completed iterator was never consumed: no finalisation was recorded.
        self::assertFalse($transport->wasIngested('job-001'));
        self::assertSame([], $this->storeFor($tree)->allPending());
    }

    /**
     * Drain the seeded two-person job and assert the discriminating outcome shared by the exclusion
     * sub-paths: one job ingested with no failure, exactly the visible I1 persisted as the sole row,
     * and the job finalised as ingested. The OTHER requested person is excluded by
     * {@see CandidateRepository::findByXrefs()} — whether because its xref is unknown or because the
     * drain's principal may not see it — so the assertions are identical, only the setup differs.
     *
     * @param Tree                  $tree      The tree whose store is read.
     * @param string                $job       The seeded job id expected to finalise.
     * @param RecordingJobTransport $transport The transport carrying the seeded two-person job.
     *
     * @return void
     */
    private function assertOnlyI1Stored(Tree $tree, string $job, RecordingJobTransport $transport): void
    {
        $summary = $this->drainService($transport)->drain(null, 20);

        self::assertSame(1, $summary->ingested);
        self::assertSame(0, $summary->failed);
        self::assertSame(1, $summary->stored);
        self::assertTrue($transport->wasIngested($job));

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
}
