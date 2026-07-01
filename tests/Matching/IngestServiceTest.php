<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

/* jscpd:ignore-start - the domain import block converges with the worked-example test's by necessity */
use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RunnerUp;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Scoring\AgeScorer;
use MagicSunday\ObituaryMatcher\Scoring\BirthScorer;
use MagicSunday\ObituaryMatcher\Scoring\CemeteryScorer;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\ConflictDetector;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Scoring\NameScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlaceScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer;
use MagicSunday\ObituaryMatcher\Scoring\RelativeScorer;
use MagicSunday\ObituaryMatcher\Support\ColognePhonetic;
use MagicSunday\ObituaryMatcher\Support\DeathFactHarvester;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\NoticeMapper;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
/* jscpd:ignore-end */
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the response → score → persist vertical slice: a validated feeder response is mapped,
 * scored by the enriched engine, classified and persisted as a pending suggestion — and a
 * person who was in the request but no longer has a held candidate is skipped without an error.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
/* jscpd:ignore-start - the UsesClass coverage block converges with the worked-example test's by necessity */
#[CoversClass(IngestService::class)]
#[UsesClass(Band::class)]
#[UsesClass(Classification::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(ConflictDetector::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(DeathFactHarvester::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(GivenNameVariants::class)]
#[UsesClass(ColognePhonetic::class)]
#[UsesClass(AgeScorer::class)]
#[UsesClass(BirthScorer::class)]
#[UsesClass(CemeteryScorer::class)]
#[UsesClass(EnrichedMatchEngine::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(NameScorer::class)]
#[UsesClass(RelativeScorer::class)]
#[UsesClass(NoticeMapper::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(ObituaryDateParser::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryRecord::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(PlaceScorer::class)]
#[UsesClass(PlausibilityScorer::class)]
#[UsesClass(RunnerUp::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
#[UsesClass(Classifier::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(ResponseValidator::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(UrlNormalizer::class)]
/* jscpd:ignore-end */
final class IngestServiceTest extends TempDirTestCase
{
    /**
     * Ingests a validated feeder response for a still-held candidate, scores it with the enriched
     * engine and persists the best result per notice as a pending suggestion.
     */
    #[Test]
    public function ingestsAResponseIntoPendingSuggestions(): void
    {
        // The notices are already validated by the time the ingest receives them (the drain reads and
        // validates the CLAIMED job's response before this call).
        $notices = $this->notices('response-valid.json', ['I1']); // results for I1
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = $this->newService();

        $result = $service->ingest($notices, ['I1' => $this->candidateMatchingErika()], $store);

        self::assertSame(1, $result->matchesStored);

        // The four IngestResult fields each reflect their own accumulation: one notice was read, one
        // held candidate was given, one row was stored and nothing went wrong (no warning).
        self::assertSame(1, $result->noticesRead);
        self::assertSame(1, $result->candidatesFound);
        self::assertSame([], $result->warnings);

        $pending = $store->allPending();
        self::assertCount(1, $pending);

        $match = $pending[0];
        self::assertSame('I1', $match->personId);
        self::assertSame(MatchStatus::Pending, $match->status);

        // The persisted payload is the full ClassifiedMatch::toArray shape, and the Erika candidate
        // genuinely scores a real probable-band match (not a forced value): the classification and
        // the harvested enriched facts are asserted against the authoritative engine output, so this
        // case fails should the candidate stop scoring or the pipeline drop a harvested fact. The
        // enriched engine harvests the cemetery and funeral date in addition to the exact death date,
        // proving the notice was scored DIRECTLY (no Phase-1 down-map that would lose burial facts).
        // The enriched profile adds the relatives/age/cemetery signals on top of the Phase-1 base, so
        // this genuine match clears the strong band (where Phase-1 alone landed it in probable).
        self::assertSame(Band::Strong->value(), $match->match['classification']);
        self::assertFalse($match->match['hardConflict']);
        self::assertSame('2024-03-12', $match->match['extractedFacts']['deathDate']);
        self::assertSame('Waldfriedhof Musterstadt', $match->match['extractedFacts']['cemetery']);
        self::assertSame('2024-03-20', $match->match['extractedFacts']['funeralDate']);
        self::assertSame('https://example.test/traueranzeige/erika', $match->obituaryUrl);
    }

    /**
     * A notice belonging to a person who no longer has a held candidate is not dropped silently: it
     * stores nothing and surfaces a non-fatal warning, so a caller (the drain) can record why a
     * read notice produced no suggestion.
     */
    #[Test]
    public function aNoticeForAPersonWithoutAHeldCandidateStoresNothingAndWarns(): void
    {
        // The response carries a notice for I1, but no candidate for I1 is held this run.
        $notices = $this->notices('response-valid.json', ['I1']);
        $store   = new FileMatchStore($this->tmp . '/store');
        $result  = $this->newService()->ingest($notices, [], $store);

        self::assertSame(1, $result->noticesRead);
        self::assertSame(0, $result->candidatesFound);
        self::assertSame(0, $result->matchesStored);

        // The warning is discriminating, not merely present: exactly one warning is collected and it
        // names the skipped person, so a regression that warned for the wrong person, dropped the id
        // or emitted an empty message fails here — the docstring promises the caller can record WHY.
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('I1', $result->warnings[0]);

        self::assertSame([], $store->allPending());
    }

    /**
     * Two notices in the same response whose URLs collapse onto one identity key (here two
     * utm-variant links for the same person) overwrite the same stored row. The count must reflect
     * the single distinct row actually persisted, not the two writes iterated — the count feeds
     * JobTransport::markIngested and may not overstate.
     */
    #[Test]
    public function countsDistinctStoredSuggestionsWhenWithinResponseDuplicatesCollapse(): void
    {
        // two I1 notices, one identity
        $notices = $this->notices('response-duplicate-identity.json', ['I1']);
        $store   = new FileMatchStore($this->tmp . '/store');
        $result  = $this->newService()->ingest($notices, ['I1' => $this->candidateMatchingErika()], $store);

        // Both notices score and both writes succeed (last-write-wins de-dup is correct), but only
        // ONE distinct identity key was persisted, so the count must be 1.
        self::assertSame(1, $result->matchesStored);
        self::assertCount(1, $store->allPending());
    }

    /**
     * Re-ingesting a job whose only stored row has since reached a terminal status writes nothing
     * and reports zero: the count reflects rows actually persisted, not notices iterated.
     */
    #[Test]
    public function reIngestOverATerminalRowStoresNothingAndReportsZero(): void
    {
        $notices = $this->notices('response-valid.json', ['I1']); // results for I1
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = $this->newService();

        // First ingest stores the single pending row and reports it.
        self::assertSame(
            1,
            $service->ingest($notices, ['I1' => $this->candidateMatchingErika()], $store)->matchesStored
        );

        // Drive that row terminal via an explicit rejection on the stored obituaryUrl.
        $store->markRejected('I1', $store->allPending()[0]->obituaryUrl ?? '', 'reviewer rejected');

        // Re-ingesting the SAME notices must store nothing (the terminal row is a no-op) and therefore
        // report zero — the count is destined for JobTransport::markIngested, so it may not overstate.
        self::assertSame(
            0,
            $service->ingest($notices, ['I1' => $this->candidateMatchingErika()], $store)->matchesStored
        );

        // No duplicate pending row was resurrected; the rejected row is still the only one.
        self::assertSame([], $store->allPending());
        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
    }

    /**
     * A requested person whose validated result carries an EMPTY notice list is silently skipped:
     * the {@see IngestService::ingest()} loop hits the `$notices === []` continue BEFORE the
     * no-held-candidate check, so it stores nothing and — unlike a person with a notice but no held
     * candidate — emits NO warning. This pins that empty-notice branch as the distinct, silent path.
     */
    #[Test]
    public function aPersonWithAnEmptyNoticeListIsSkippedSilentlyWithoutAWarning(): void
    {
        // results map I1 to an empty notice list (the feeder found no notice for the requested id).
        $notices = $this->notices('response-empty-notices.json', ['I1']);
        $store   = new FileMatchStore($this->tmp . '/store');

        // A candidate IS held for I1, so this is NOT the no-candidate path: the empty list short-
        // circuits before that check, proving the two branches are genuinely distinct.
        $result = $this->newService()->ingest($notices, ['I1' => $this->candidateMatchingErika()], $store);

        // No notice was read, nothing was stored, and the empty-notice person is silent — the warning
        // belongs ONLY to the no-held-candidate path, so a regression that warned here would fail.
        self::assertSame(0, $result->noticesRead);
        self::assertSame(0, $result->matchesStored);
        self::assertSame([], $result->warnings);
        self::assertSame([], $store->allPending());
    }

    /**
     * Pins the meaning of the {@see IngestResult::$candidatesFound} field: it is the size of the
     * INPUT candidate map the run was given, NOT the number of rows persisted. The response mentions
     * only I1, so just one row is stored, yet two candidates were held — proving the count reports the
     * held-candidate map and never collapses to the stored-row tally.
     */
    #[Test]
    public function candidatesFoundCountsTheInputMapNotTheStoredRows(): void
    {
        // The response carries a single notice, for I1 only.
        $notices = $this->notices('response-valid.json', ['I1', 'I2']);
        $store   = new FileMatchStore($this->tmp . '/store');

        // Two candidates are held this run (I1 matches the notice; I2 has no notice in the response).
        $result = $this->newService()->ingest(
            $notices,
            [
                'I1' => $this->candidateMatchingErika(),
                'I2' => $this->unmatchedCandidate(),
            ],
            $store,
        );

        // candidatesFound is the held-map size (2), independent of the single row that was persisted.
        self::assertSame(2, $result->candidatesFound);
        self::assertSame(1, $result->matchesStored);
        self::assertCount(1, $store->allPending());
    }

    /**
     * Builds the ingest service wired to the enriched scoring engine and the classifier. The service
     * is now transport-agnostic — it receives already-validated notices — so it no longer holds a
     * response reader. The persistence store is passed per ingest() call, so it is deliberately NOT
     * part of this construction.
     *
     * @return IngestService The service under test.
     */
    private function newService(): IngestService
    {
        return new IngestService(new EnrichedMatchEngine(), new Classifier());
    }

    /**
     * Builds the already-validated notices the ingest receives, by narrowing a feeder-response fixture
     * through the SAME {@see ResponseValidator} the drain uses — so the test feeds the ingest exactly
     * the seam shape it sees in production, without re-coupling to the on-disk reader.
     *
     * @param string       $fixture           The fixture file name under tests/fixtures (jobId "job-1").
     * @param list<string> $expectedPersonIds The requested person ids (the validator's ownership boundary).
     *
     * @return array<string, list<DeathNoticeRecord>> The validated notices keyed by person id.
     */
    private function notices(string $fixture, array $expectedPersonIds): array
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/' . $fixture);
        self::assertIsString($contents, 'fixture is readable');

        /** @var array<int|string, mixed> $payload */
        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return (new ResponseValidator())->validate($payload, 'job-1', $expectedPersonIds);
    }

    /**
     * Builds a candidate that genuinely scores against the fixture "Erika Mustermann geb. Mueller"
     * notice. The shape mirrors the Phase-1 worked example (born approximately 1938, born Mueller,
     * married into the notice surname, resident in Musterstadt), so the classification is a real
     * strong-band match rather than a forced value.
     *
     * @return PersonCandidate The scoring candidate for person I1.
     */
    private function candidateMatchingErika(): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mueller', 'Mueller', ['Mustermann']),
            DateRange::known(new DateValue(1936, 1, 1), new DateValue(1940, 12, 31), DatePrecision::Approximate),
            null,
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );
    }

    /**
     * Builds a second, synthetic candidate (person I2) that has no notice in the response. It exists
     * only to make the held-candidate map larger than the set of persisted rows, so a test can prove
     * candidatesFound reports the input map size rather than the stored-row count.
     *
     * @return PersonCandidate The held-but-unmatched candidate for person I2.
     */
    private function unmatchedCandidate(): PersonCandidate
    {
        return new PersonCandidate(
            'I2',
            Gender::Male,
            new PersonName(['Hans'], null, 'Beispiel', 'Beispiel'),
            DateRange::unknown(),
            null,
            [],
            DateRange::unknown(),
        );
    }
}
