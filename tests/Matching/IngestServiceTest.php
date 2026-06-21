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
use MagicSunday\ObituaryMatcher\Parsing\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\ConflictDetector;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Scoring\NameScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlaceScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\KoelnerPhonetik;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\NoticeMapper;
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
/* jscpd:ignore-end */
use MagicSunday\ObituaryMatcher\Test\Queue\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests the response → score → persist vertical slice: a validated feeder response is mapped,
 * scored by the unchanged Phase-1 engine, classified and persisted as a pending suggestion — and a
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
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(GivenNameVariants::class)]
#[UsesClass(KoelnerPhonetik::class)]
#[UsesClass(MatchEngine::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(NameScorer::class)]
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
#[UsesClass(AtomicFile::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(ResponseReader::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(UrlNormalizer::class)]
/* jscpd:ignore-end */
final class IngestServiceTest extends TempDirTestCase
{
    /**
     * Ingests a validated feeder response for a still-held candidate, scores it with the unchanged
     * engine and persists the best result per notice as a pending suggestion.
     */
    #[Test]
    public function ingestsAResponseIntoPendingSuggestions(): void
    {
        $this->placeResponse('job-1', 'response-valid.json');     // results for I1
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = new IngestService(
            new ResponseReader(new QueuePaths($this->tmp)),
            new MatchEngine(),
            new Classifier(),
            $store,
        );

        $stored = $service->ingest('job-1', ['I1'], ['I1' => $this->candidateMatchingErika()]);

        self::assertSame(1, $stored);
        $pending = $store->allPending();
        self::assertCount(1, $pending);

        $match = $pending[0];
        self::assertSame('I1', $match->personId);
        self::assertSame(MatchStatus::Pending, $match->status);

        // The persisted payload is the full ClassifiedMatch::toArray shape, and the Erika candidate
        // genuinely scores a real probable-band match (not a forced value): the classification and
        // the harvested exact death date are asserted against the authoritative engine output, so
        // this case fails should the candidate stop scoring or the pipeline drop the harvested fact.
        self::assertSame(Band::Probable->value(), $match->match['classification']);
        self::assertFalse($match->match['hardConflict']);
        self::assertSame('2024-03-12', $match->match['extractedFacts']['deathDate']);
        self::assertSame('https://example.test/traueranzeige/erika', $match->obituaryUrl);
    }

    /**
     * Two notices in the same response whose URLs collapse onto one identity key (here two
     * utm-variant links for the same person) overwrite the same stored row. The count must reflect
     * the single distinct row actually persisted, not the two writes iterated — the count feeds
     * QueueClient::markDone and may not overstate.
     */
    #[Test]
    public function countsDistinctStoredSuggestionsWhenWithinResponseDuplicatesCollapse(): void
    {
        $this->placeResponse('job-1', 'response-duplicate-identity.json'); // two I1 notices, one identity
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = new IngestService(
            new ResponseReader(new QueuePaths($this->tmp)),
            new MatchEngine(),
            new Classifier(),
            $store,
        );

        $stored = $service->ingest('job-1', ['I1'], ['I1' => $this->candidateMatchingErika()]);

        // Both notices score and both writes succeed (last-write-wins de-dup is correct), but only
        // ONE distinct identity key was persisted, so the count must be 1.
        self::assertSame(1, $stored);
        self::assertCount(1, $store->allPending());
    }

    /**
     * Re-ingesting a job whose only stored row has since reached a terminal status writes nothing
     * and reports zero: the count reflects rows actually persisted, not notices iterated.
     */
    #[Test]
    public function reIngestOverATerminalRowStoresNothingAndReportsZero(): void
    {
        $this->placeResponse('job-1', 'response-valid.json');     // results for I1
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = new IngestService(
            new ResponseReader(new QueuePaths($this->tmp)),
            new MatchEngine(),
            new Classifier(),
            $store,
        );

        // First ingest stores the single pending row and reports it.
        self::assertSame(1, $service->ingest('job-1', ['I1'], ['I1' => $this->candidateMatchingErika()]));

        // Drive that row terminal via an explicit rejection on the stored obituaryUrl.
        $store->markRejected('I1', $store->allPending()[0]->obituaryUrl ?? '', 'reviewer rejected');

        // Re-ingesting the SAME job must store nothing (the terminal row is a no-op) and therefore
        // report zero — the count is destined for QueueClient::markDone, so it may not overstate.
        self::assertSame(0, $service->ingest('job-1', ['I1'], ['I1' => $this->candidateMatchingErika()]));

        // No duplicate pending row was resurrected; the rejected row is still the only one.
        self::assertSame([], $store->allPending());
        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
    }

    /**
     * A person who was in the request but no longer has a held candidate is skipped without an
     * error and contributes nothing to the stored count.
     */
    #[Test]
    public function skipsAPersonWhoseCandidateVanishedSinceEnqueue(): void
    {
        $this->placeResponse('job-1', 'response-valid.json');     // results for I1 — I1 WAS requested (ownership ok)
        $store  = new FileMatchStore($this->tmp . '/store');
        $stored = (new IngestService(new ResponseReader(new QueuePaths($this->tmp)), new MatchEngine(), new Classifier(), $store))
            ->ingest('job-1', ['I1'], []);                         // ...but no candidate held now (e.g. became private)

        self::assertSame(0, $stored);
        self::assertSame([], $store->allPending());
    }

    /**
     * Builds a candidate that genuinely scores against the fixture "Erika Mustermann geb. Mueller"
     * notice. The shape mirrors the Phase-1 worked example (born approximately 1938, born Mueller,
     * married into the notice surname, resident in Musterstadt), so the classification is a real
     * probable-band match rather than a forced value.
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
}
