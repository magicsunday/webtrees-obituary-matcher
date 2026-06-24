<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FeederCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateCriteria;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueSummary;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function file_put_contents;
use function mkdir;
use function scandir;
use function str_starts_with;

use const SCANDIR_SORT_NONE;

/**
 * Integration scenarios for the {@see EnqueueService}: each pins the discriminating triple — the
 * {@see EnqueueSummary} counters, the written `queued/<job>/request.json` content (candidates +
 * per-candidate excludedHosts, read back through the validating {@see FeederRequestReader} and the
 * raw row) and, for the skip cases, the absence of the skipped person from the request.
 *
 * The reference year is pinned (1929) so the three "Searchable" fixtures (born 1925/1928/1930) are
 * deterministic candidates regardless of the wall clock.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnqueueService::class)]
#[UsesClass(EnqueueSummary::class)]
#[UsesClass(CandidateCriteria::class)]
#[UsesClass(CandidateRepository::class)]
#[UsesClass(MatchStoreFactory::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(StoredMatchKey::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(FeederRequestReader::class)]
#[UsesClass(QueueClient::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(JobState::class)]
#[UsesClass(FeederCandidateRequest::class)]
#[UsesClass(FeederRequest::class)]
#[UsesClass(FeederRequestFactory::class)]
#[UsesClass(JobId::class)]
#[UsesClass(QueryGenerator::class)]
#[UsesClass(UrlHostNormalizer::class)]
final class EnqueueServiceTest extends AbstractEnqueueTestCase
{
    /**
     * The reference year that keeps the 1925/1928/1930 fixtures eligible at any wall-clock time: at
     * minAge 90 a person born in 1930 must have reached 90 by this year (2025 - 1930 = 95 >= 90), and
     * the young 2010 fixture (15 years) stays correctly excluded.
     */
    private const int REFERENCE_YEAR = 2025;

    /**
     * (1) Happy path: a single eligible candidate (a 1-person tree) is enqueued, exactly one queued
     * job is written carrying its personId + a non-empty query list + excludedHosts: [], and the
     * summary reports it.
     *
     * @return void
     */
    #[Test]
    public function aSingleEligibleCandidateIsEnqueuedWithAnEmptyExcludedHostList(): void
    {
        $tree = $this->importFixtureTree(
            "0 HEAD\n1 SOUR t\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n1 NAME Otto /Searchable/\n2 GIVN Otto\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n0 TRLR\n",
            'enqueue-happy',
        );

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(1, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);
        self::assertSame(0, $summary->excludedHosts);

        $candidates = $this->queuedCandidates($summary->jobId);

        self::assertCount(1, $candidates);
        self::assertSame('I1', $candidates[0]['personId']);
        self::assertNotEmpty($candidates[0]['queries']);
        self::assertArrayHasKey('excludedHosts', $candidates[0]);
        self::assertSame([], $candidates[0]['excludedHosts']);

        // The validating reader agrees on the requested set.
        $request = (new FeederRequestReader($this->paths(), 5_242_880))->read($summary->jobId, JobState::Queued);
        self::assertSame(['I1'], $request['requestedPersonIds']);
    }

    /**
     * (2) In-flight dedup, queued state: a person already in a queued job for this tree is skipped,
     * so only the remaining two candidates are enqueued and the skipped person is absent.
     *
     * @return void
     */
    #[Test]
    public function aPersonAlreadyInAQueuedJobIsSkipped(): void
    {
        $tree = $this->ottoTree('enqueue-inflight-queued');

        $this->seedInflightJob('job-existing-q', JobState::Queued, $tree->id(), ['I1']);

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(2, $summary->candidates);
        self::assertSame(1, $summary->skippedInflight);

        $ids = $this->queuedPersonIds($summary->jobId);

        self::assertSame(['I2', 'I3'], $ids);
        self::assertNotContains('I1', $ids);
    }

    /**
     * (3) In-flight dedup, done state: a person already in a DONE job (the feeder produced a result
     * not yet ingested) is skipped — the gap the 4-state scan closes.
     *
     * @return void
     */
    #[Test]
    public function aPersonAlreadyInADoneJobIsSkipped(): void
    {
        $tree = $this->ottoTree('enqueue-inflight-done');

        $this->seedInflightJob('job-existing-d', JobState::Done, $tree->id(), ['I2']);

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(2, $summary->candidates);
        self::assertSame(1, $summary->skippedInflight);

        $ids = $this->queuedPersonIds($summary->jobId);

        self::assertSame(['I1', 'I3'], $ids);
        self::assertNotContains('I2', $ids);
    }

    /**
     * (4) excludedHosts from a pending + an uncertain match on two distinct hosts become the
     * candidate's sorted, deduplicated excludedHosts list.
     *
     * @return void
     */
    #[Test]
    public function pendingAndUncertainMatchesOnTwoHostsBecomeSortedDedupedExcludedHosts(): void
    {
        $tree = $this->ottoTree('enqueue-excluded-hosts');

        // Two open matches on two hosts (the second deliberately the lexicographically smaller one,
        // so the assertion proves the SORT) plus a www. duplicate of the first to prove the dedup.
        $this->seedStoreRow($tree, 'I1', 'https://www.zeitung.test/n/1', MatchStatus::Pending);
        $this->seedStoreRow($tree, 'I1', 'https://zeitung.test/n/2', MatchStatus::Uncertain);
        $this->seedStoreRow($tree, 'I1', 'https://anzeiger.test/n/3', MatchStatus::Pending);

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(3, $summary->candidates);
        // Two distinct hosts for I1, none for the others.
        self::assertSame(2, $summary->excludedHosts);

        $candidates = $this->queuedCandidates($summary->jobId);
        $byId       = [];

        foreach ($candidates as $candidate) {
            /** @var string $personId */
            $personId        = $candidate['personId'];
            $byId[$personId] = $candidate['excludedHosts'];
        }

        self::assertSame(['anzeiger.test', 'zeitung.test'], $byId['I1']);
        self::assertSame([], $byId['I2']);
        self::assertSame([], $byId['I3']);
    }

    /**
     * (5) Rejected + confirmed-only on the same host → excludedHosts is [] (the terminal statuses
     * contribute nothing), and the key is present-and-empty.
     *
     * @return void
     */
    #[Test]
    public function aRejectedAndConfirmedRowOnTheSameHostYieldNoExcludedHosts(): void
    {
        $tree = $this->importFixtureTree(
            "0 HEAD\n1 SOUR t\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n1 NAME Otto /Searchable/\n2 GIVN Otto\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n0 TRLR\n",
            'enqueue-terminal-hosts',
        );

        $this->seedStoreRow($tree, 'I1', 'https://zeitung.test/rejected', MatchStatus::Rejected);
        $this->seedStoreRow($tree, 'I1', 'https://zeitung.test/confirmed', MatchStatus::Confirmed);

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(1, $summary->candidates);
        self::assertSame(0, $summary->excludedHosts);

        $candidates = $this->queuedCandidates($summary->jobId);

        self::assertArrayHasKey('excludedHosts', $candidates[0]);
        self::assertSame([], $candidates[0]['excludedHosts']);
    }

    /**
     * (6) A malformed in-flight request.json is warned + ignored; the enqueue still produces its job
     * (the corrupt foreign job neither blocks the producer nor skips any candidate).
     *
     * @return void
     */
    #[Test]
    public function aMalformedInflightRequestIsIgnoredAndTheEnqueueStillRuns(): void
    {
        $tree = $this->ottoTree('enqueue-malformed-inflight');

        // A schema-invalid in-flight request (no schemaVersion/jobId/candidates) in the queued state.
        $badDir = $this->paths()->stateRoot(JobState::Queued->value) . '/job-corrupt';
        mkdir($badDir, 0o700, true);
        AtomicFile::writeJson($badDir . '/request.json', ['garbage' => true]);

        $this->assertEnqueueIgnoresTheCorruptInflightJob($tree);
    }

    /**
     * (10) A syntactically-broken in-flight request.json (raw non-JSON bytes, NOT a schema-invalid
     * but well-formed document) is warned + ignored; the enqueue still produces its job. Distinct from
     * scenario (6): there AtomicFile::readJsonCapped decodes successfully and the schema check raises a
     * ResponseValidationException, whereas here the decode itself fails — which must surface as a
     * RuntimeException the in-flight scan catches, never an uncaught JsonException that aborts the run.
     *
     * @return void
     */
    #[Test]
    public function aBrokenJsonInflightRequestIsIgnoredAndTheEnqueueStillRuns(): void
    {
        $tree = $this->ottoTree('enqueue-broken-json-inflight');

        // Write RAW, syntactically-broken bytes (bypassing AtomicFile::writeJson, which always
        // produces valid JSON) for a valid-job-id-pattern directory so the scan reaches the decode.
        $badDir = $this->paths()->stateRoot(JobState::Queued->value) . '/job-broken';
        mkdir($badDir, 0o700, true);
        file_put_contents($badDir . '/request.json', '{not json');

        $this->assertEnqueueIgnoresTheCorruptInflightJob($tree);
    }

    /**
     * Runs the enqueue against the Otto tree and asserts the producer ignored a pre-seeded corrupt
     * in-flight job: a job is still produced, no candidate is skipped and all three persons are
     * enqueued. Shared by the schema-invalid (6) and broken-JSON (10) scenarios, which differ only in
     * HOW the corrupt in-flight directory was seeded.
     *
     * @param Tree $tree The Otto tree the corrupt in-flight job was seeded against.
     *
     * @return void
     */
    private function assertEnqueueIgnoresTheCorruptInflightJob(Tree $tree): void
    {
        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(3, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);

        self::assertSame(['I1', 'I2', 'I3'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * (7) --limit caps the candidate count; the surviving set is the first N by personId order.
     *
     * @return void
     */
    #[Test]
    public function limitCapsTheCandidateCountDeterministically(): void
    {
        $tree = $this->ottoTree('enqueue-limit');

        $summary = $this->enqueueService()->enqueue($tree->id(), 2, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(2, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);

        // The first two by personId order (I1, I2); I3 is capped out.
        self::assertSame(['I1', 'I2'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * (11) Bounding (issue #38): with more eligible candidates (I1..I5) than --limit (3), the producer
     * emits exactly the three lowest-xref candidates — I4 and I5 are bounded out of the emitted set,
     * which stays the deterministic lowest-xref-first prefix. The lazy/bounded HYDRATION contract the
     * producer relies on is pinned in {@see CandidateRepositoryTest}; this test pins the emitted set.
     *
     * @return void
     */
    #[Test]
    public function limitBoundsTheEligibleSetToTheLowestXrefPrefix(): void
    {
        $tree = $this->searchableTree('enqueue-bounding', 5);

        $summary = $this->enqueueService()->enqueue($tree->id(), 3, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(3, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);

        // The three lowest xrefs only; I4 and I5 are bounded out of the emitted set.
        self::assertSame(['I1', 'I2', 'I3'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * (12) Bare-numeric xref ordering: the cap tiebreak is lexicographic (byte-wise), NOT numeric, so
     * over xrefs "2"/"10"/"100" a --limit of 2 enqueues the lexicographic prefix ["10", "100"], not
     * the numeric ["2", "10"]. webtrees never generates bare-numeric xrefs, but a third-party GEDCOM
     * with numeric record ids imports them verbatim; this freezes the engine-independent
     * lexicographic contract so a future change back to a numeric sort cannot silently flip the
     * enqueued set.
     *
     * @return void
     */
    #[Test]
    public function theCapTiebreakOverBareNumericXrefsIsLexicographicNotNumeric(): void
    {
        $tree = $this->importFixtureTree(
            "0 HEAD\n1 SOUR t\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n"
            . "0 @2@ INDI\n1 NAME Two /Searchable/\n2 GIVN Two\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n"
            . "0 @10@ INDI\n1 NAME Ten /Searchable/\n2 GIVN Ten\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n"
            . "0 @100@ INDI\n1 NAME Hundred /Searchable/\n2 GIVN Hundred\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n"
            . "0 TRLR\n",
            'enqueue-numeric-xref',
        );

        $summary = $this->enqueueService()->enqueue($tree->id(), 2, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(2, $summary->candidates);

        // Lexicographic prefix: "10" and "100" sort before "2"; a numeric sort would have picked "2".
        self::assertSame(['10', '100'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * (13) A non-positive --limit enqueues nothing: the explicit cap guard returns an empty summary
     * (jobId null, all counters zero) and writes no job. Without the guard the `count() === $limit`
     * break could never fire for a zero/negative limit and the loop would drain and enqueue the whole
     * eligible population.
     *
     * @return void
     */
    #[Test]
    public function aNonPositiveLimitEnqueuesNothing(): void
    {
        $tree = $this->ottoTree('enqueue-zero-limit');

        $summary = $this->enqueueService()->enqueue($tree->id(), 0, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNull($summary->jobId);
        self::assertSame(0, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);
        self::assertSame(0, $summary->excludedHosts);

        self::assertSame([], $this->queuedJobIds());
    }

    /**
     * (14) Bounding meets in-flight dedup: over I1..I5 with an in-flight job for I1 AND I5 and
     * --limit 2, the producer emits [I2, I3] and reports skippedInflight 1 — only the in-flight I1
     * stepped over WHILE filling the cap is counted; the in-flight I5 sits beyond the cap boundary,
     * is never pulled, and is NOT counted. This is the discriminator for the within-batch
     * skippedInflight semantics (the old whole-population slice would have reported 2).
     *
     * @return void
     */
    #[Test]
    public function skippedInflightCountsOnlyTheInFlightSteppedOverWhileFillingTheCap(): void
    {
        $tree = $this->searchableTree('enqueue-bounding-inflight', 5);

        // I1 is stepped over while filling the cap (counted); I5 sits beyond the cap boundary and is
        // never reached (not counted).
        $this->seedInflightJob('job-existing-mix', JobState::Queued, $tree->id(), ['I1', 'I5']);

        $summary = $this->enqueueService()->enqueue($tree->id(), 2, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(2, $summary->candidates);
        self::assertSame(1, $summary->skippedInflight);

        self::assertSame(['I2', 'I3'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * (8) Zero eligible candidates → no job written, summary jobId null and counters zero.
     *
     * @return void
     */
    #[Test]
    public function noEligibleCandidatesWritesNoJob(): void
    {
        // A young tree: born 2010 is far below the age bound, so no candidate qualifies.
        $tree = $this->importFixtureTree(
            "0 HEAD\n1 SOUR t\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n1 NAME Young /Searchable/\n2 GIVN Young\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 2010\n0 TRLR\n",
            'enqueue-empty',
        );

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNull($summary->jobId);
        self::assertSame(0, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);
        self::assertSame(0, $summary->excludedHosts);

        // No queued job was written.
        self::assertSame([], $this->queuedJobIds());
    }

    /**
     * (9) Tree filter: an in-flight job for a DIFFERENT treeId carrying the same xref does NOT skip
     * the candidate — the discriminator for the tree-filtered in-flight dedup.
     *
     * @return void
     */
    #[Test]
    public function anInflightJobForADifferentTreeDoesNotSkipASharedXref(): void
    {
        $tree = $this->importFixtureTree(
            "0 HEAD\n1 SOUR t\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n1 NAME Otto /Searchable/\n2 GIVN Otto\n2 SURN Searchable\n1 SEX M\n1 BIRT\n2 DATE 17 MAR 1930\n0 TRLR\n",
            'enqueue-tree-filter',
        );

        // An in-flight job for a FOREIGN tree id carrying the SAME xref I1 must NOT block this tree's
        // own I1 — they are different people.
        $this->seedInflightJob('job-foreign-tree', JobState::Queued, $tree->id() + 999, ['I1']);

        $summary = $this->enqueueService()->enqueue($tree->id(), 50, 90, 'de-DE', self::REFERENCE_YEAR);

        self::assertNotNull($summary->jobId);
        self::assertSame(1, $summary->candidates);
        self::assertSame(0, $summary->skippedInflight);

        self::assertSame(['I1'], $this->queuedPersonIds($summary->jobId));
    }

    /**
     * Read the candidate list of a queued job's request.json straight off disk (the raw row, so the
     * assertions see the exact `excludedHosts` shape the producer wrote).
     *
     * @param string $jobId The queued job id.
     *
     * @return list<array<string, mixed>> The candidate entries.
     */
    private function queuedCandidates(string $jobId): array
    {
        $path = $this->paths()->stateRoot(JobState::Queued->value) . '/' . $jobId . '/request.json';
        $data = AtomicFile::readJsonCapped($path, 5_242_880);

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $data['candidates'];

        return $candidates;
    }

    /**
     * Read just the personIds of a queued job's request, in written order.
     *
     * @param string $jobId The queued job id.
     *
     * @return list<string> The requested person ids.
     */
    private function queuedPersonIds(string $jobId): array
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
     * List the real job directories currently in the queued state (excluding dot + temp entries).
     *
     * @return list<string> The queued job ids.
     */
    private function queuedJobIds(): array
    {
        $root    = $this->paths()->stateRoot(JobState::Queued->value);
        $entries = scandir($root, SCANDIR_SORT_NONE);

        if ($entries === false) {
            return [];
        }

        $jobs = [];

        foreach ($entries as $entry) {
            if (
                ($entry === '.')
                || ($entry === '..')
                || str_starts_with($entry, '.tmp-')
            ) {
                continue;
            }

            $jobs[] = $entry;
        }

        return $jobs;
    }
}
