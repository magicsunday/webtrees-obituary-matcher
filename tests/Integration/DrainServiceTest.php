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
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
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

use function file_put_contents;
use function json_encode;
use function mkdir;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

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
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\DrainOutcome::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\FileJobTransport::class)]
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
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\JobStatus::class)]
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

        $this->assertJobOneParkedInFailedIngestWithEmptyStore($tree);
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
        $corruptDir = $this->seedDoneJobWithValidRequest($tree);
        AtomicFile::writeJson(
            $corruptDir . '/response.json',
            [
                'schemaVersion' => 999,
                'jobId'         => 'job-001',
                'results'       => [],
            ],
        );

        // The corrupt job is parked in failed-ingest while a valid sibling still ingests.
        $this->assertBadJobParkedWhileValidJobIngests($tree, 'job-001');
    }

    /**
     * A TORN response.json (malformed/truncated JSON) is parked as ingest_failed, NOT request_failed:
     * the read of the response shares the SAME try block as the ingest, so the plain RuntimeException
     * {@see AtomicFile::readJsonCapped()} throws on a decode failure
     * (a converted JsonException, which is NOT a ResponseValidationException) is caught by the Throwable
     * arm and recorded under the ingest_failed reason category. This preserves the file path's original
     * behaviour — before the slice that split the response read into its own catch, a torn-response IO
     * failure surfaced from inside ingest() and was caught as ingest_failed — and pins that a
     * RESPONSE-read fault is never mislabelled as a request fault.
     */
    #[Test]
    public function aTornResponseJobIsParkedAsIngestFailed(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // A valid request.json (schema v3) but a TORN response.json: the JSON is truncated, so
        // readJsonCapped() throws a plain RuntimeException (a converted JsonException), NOT a
        // ResponseValidationException. The job is claimed (done -> ingesting) before the read runs.
        $jobDir = $this->seedDoneJobWithValidRequest($tree);

        // Deliberately malformed (truncated) JSON so the decode throws — written raw because
        // AtomicFile::writeJson would only ever produce well-formed JSON.
        file_put_contents($jobDir . '/response.json', '{"schemaVersion": 1, "jobId": "job-001", "results":');

        // The torn-response job is parked in failed-ingest, counted failed, and persisted no row.
        $this->assertJobOneParkedInFailedIngestWithEmptyStore($tree);

        // The reason CATEGORY is ingest_failed (a RESPONSE-read IO fault routed through the shared
        // ingest try block), NOT request_failed — a response-read failure must never be mislabelled as
        // a request fault.
        self::assertSame('ingest_failed', (new QueueClient($this->paths()))->status('job-001')->error);
    }

    /**
     * The request read isolates a plain IO/system RuntimeException the SAME way it isolates a
     * validation reject: an oversize request.json makes {@see FeederRequestReader::read()} ->
     * {@see AtomicFile::readJsonCapped()} throw a plain RuntimeException ("exceeds the size cap"),
     * which is NOT a ResponseValidationException. Before the catch (RuntimeException) arm that
     * propagated uncaught and crashed the whole drain, stranding the claimed job in ingesting/ and
     * losing the valid sibling. This pins that the oversize job is parked in failed-ingest while the
     * valid sibling still ingests — so a single torn/oversize request never strands the batch.
     */
    #[Test]
    public function anOversizeRequestJobIsParkedWhileTheValidJobStillIngests(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // job-001 carries an OVERSIZE request.json: still valid JSON, but padded past the reader's
        // 5 MiB cap so readJsonCapped() throws a plain RuntimeException (an IO/system failure), NOT a
        // ResponseValidationException. The job is claimed (done -> ingesting) before the read runs.
        $oversizeDir = $this->paths()->doneDir('job-001');
        mkdir($oversizeDir, 0o700, true);

        $oversizeRequest = json_encode(
            [
                'schemaVersion' => 3,
                'jobId'         => 'job-001',
                'treeId'        => $tree->id(),
                'candidates'    => [['personId' => 'I1']],
                // One byte past the reader's cap, derived from the constant so the pin tracks it.
                'padding' => str_repeat('x', QueueLimits::FEEDER_FILE_MAX_BYTES + 1),
            ],
            JSON_THROW_ON_ERROR,
        );
        file_put_contents($oversizeDir . '/request.json', $oversizeRequest);

        // The oversize job is parked in failed-ingest while a valid sibling still ingests.
        $this->assertBadJobParkedWhileValidJobIngests($tree, 'job-001');
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
                'schemaVersion' => 3,
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
     * Discovery orders done jobs by their job id NATURALLY, not lexicographically: with the two
     * unpadded ids job-2 (older) and job-10, a limit-1 drain must process the oldest job-2, not the
     * lexicographically-smaller job-10. A plain string sort would put "job-10" before "job-2" and
     * wrongly ingest the NEWER job, so this pins the natural-sort oldest-first contract that also
     * governs which jobs survive the limit cap.
     */
    #[Test]
    public function discoveryPicksTheOldestJobUnderNaturalOrdering(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // job-2 is older than job-10; lexicographically "job-10" < "job-2", so a string sort would
        // pick job-10 under the limit of 1 — the bug this test guards against.
        $this->seedDoneJob('job-2', $tree->id(), 'I1', 'Otto Searchable');
        $this->seedDoneJob('job-10', $tree->id(), 'I1', 'Otto Searchable');

        $summary = $this->drainService()->drain(null, 1);

        // Exactly one job processed (the cap), and it is the oldest job-2 — proven by both its
        // queue end-state and the job-tagged notice URL persisted to the store.
        self::assertSame(1, $summary->ingested);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf('job-2'));
        self::assertSame(JobState::Done, $this->paths()->stateOf('job-10'));

        $pending = $this->storeFor($tree)->allPending();
        self::assertCount(1, $pending);
        self::assertSame('https://example.test/job-2', $pending[0]->obituaryUrl);
    }

    /**
     * The stale tally counts only real job directories left in ingesting/, ignoring a stray non-job
     * entry (a name failing the job-id pattern, e.g. a `.DS_Store`-style file). Discovery already
     * filters such entries; this pins that countStale() filters identically, so an operator-facing
     * stale count is never inflated by a foreign filesystem artefact.
     */
    #[Test]
    public function countStaleIgnoresANonJobEntryInIngesting(): void
    {
        $tree = $this->ottoTree('fixture-a');

        // A fresh done job so the drain has something to process and reaches the stale count.
        $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        // A real stale ingesting job (a valid job id left by a crash).
        $staleDir = $this->paths()->ingestingDir('job-002');
        mkdir($staleDir, 0o700, true);

        // A stray non-job entry directly in the ingesting state root: its name fails JOB_ID_PATTERN
        // (the dot makes it an invalid job id), so it must NOT be counted into the stale tally.
        $ingestingRoot = $this->paths()->stateRoot(JobState::Ingesting->value);
        file_put_contents($ingestingRoot . '/not.a.job', '');

        $summary = $this->drainService()->drain(null, 20);

        // Only the one real stale job is counted; the stray entry is ignored.
        self::assertSame(1, $summary->ingested);
        self::assertSame(1, $summary->stale);
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
     * Drain the whole batch and assert the single-job failure outcome shared by the "one bad job, no
     * valid sibling" scenarios: nothing ingested or stored, exactly one job counted failed, job-001
     * parked in failed-ingest (not stranded in ingesting/) and the store left empty. The caller may
     * additionally assert the recorded failure reason category.
     *
     * @param Tree $tree The tree whose (empty) store is checked.
     *
     * @return void
     */
    private function assertJobOneParkedInFailedIngestWithEmptyStore(Tree $tree): void
    {
        $summary = $this->drainService()->drain(null, 20);

        self::assertSame(0, $summary->ingested);
        self::assertSame(1, $summary->failed);
        self::assertSame(0, $summary->stored);

        self::assertSame(JobState::FailedIngest, $this->paths()->stateOf('job-001'));
        self::assertSame([], $this->storeFor($tree)->allPending());
    }

    /**
     * Seed a done job ('job-001') carrying ONLY a valid request.json (schema v3, the single held
     * candidate I1) and return its directory, so the caller can drop in its own response.json shape
     * (a schema-invalid one, a torn one, …). Shared by the response-fault scenarios whose request half
     * is byte-identical, keeping the duplicate seeding out of each test body.
     *
     * @param Tree $tree The tree the request belongs to.
     *
     * @return string The seeded done job directory (absolute path).
     */
    private function seedDoneJobWithValidRequest(Tree $tree): string
    {
        $jobDir = $this->paths()->doneDir('job-001');
        mkdir($jobDir, 0o700, true);
        AtomicFile::writeJson(
            $jobDir . '/request.json',
            [
                'schemaVersion' => 3,
                'jobId'         => 'job-001',
                'treeId'        => $tree->id(),
                'candidates'    => [['personId' => 'I1']],
            ],
        );

        return $jobDir;
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
                'schemaVersion' => 3,
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
