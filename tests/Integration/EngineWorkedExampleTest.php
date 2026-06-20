<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

/* jscpd:ignore-start - the domain import block converges with the unit tests' by necessity */
use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RunnerUp;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\ConflictDetector;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Scoring\NameScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlaceScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\KoelnerPhonetik;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
/* jscpd:ignore-end */
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function usort;

/**
 * End-to-end integration tests wiring the parsers, the engine and the classifier
 * across the worked example and the two namesake/exact-date control cases.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[UsesClass(Band::class)]
#[UsesClass(Classification::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(ConflictDetector::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(GivenNameVariants::class)]
#[UsesClass(KoelnerPhonetik::class)]
#[UsesClass(MatchEngine::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(NameScorer::class)]
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
final class EngineWorkedExampleTest extends TestCase
{
    /**
     * Builds a "Rainer Vorbild" candidate from a parsed birth date and optional place.
     *
     * @param string      $id     The candidate identifier.
     * @param string      $birth  The raw birth date string to parse.
     * @param list<Place> $places The candidate's known residences.
     *
     * @return PersonCandidate The candidate.
     */
    private function rainerCandidate(string $id, string $birth, array $places): PersonCandidate
    {
        return new PersonCandidate(
            $id,
            Gender::Male,
            new PersonName(['Rainer'], null, 'Vorbild', null),
            ObituaryDateParser::parse($birth),
            null,
            $places,
            DateRange::unknown(),
        );
    }

    /**
     * Builds a "Rainer Vorbild" obituary notice from a parsed birth date and optional place.
     *
     * @param string     $birth The raw birth date string to parse.
     * @param string     $url   The source URL.
     * @param Place|null $place The place mentioned in the notice.
     *
     * @return ObituaryRecord The notice.
     */
    private function rainerNotice(string $birth, string $url, ?Place $place): ObituaryRecord
    {
        return new ObituaryRecord(
            'Rainer Vorbild',
            ObituaryNameParser::parse('Rainer Vorbild'),
            ObituaryDateParser::parse($birth),
            ObituaryDateParser::parse('23.03.2026'),
            $place,
            $url,
            'example.test',
        );
    }

    /**
     * The worked example (Elise/Elisabeth, married Schmidt) is a strong match and
     * harvests the exact death date as an extracted fact.
     */
    #[Test]
    public function workedExampleIsAStrongMatch(): void
    {
        $candidate = new PersonCandidate(
            'I1234',
            Gender::Female,
            new PersonName(['Elise'], null, 'Mueller', 'Mueller', ['Schmidt']),
            DateRange::known(new DateValue(1936, 1, 1), new DateValue(1940, 12, 31), DatePrecision::Approximate),
            null,
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );

        $notice = new ObituaryRecord(
            'Elisabeth Schmidt geb. Mueller',
            ObituaryNameParser::parse('Elisabeth Schmidt geb. Mueller'),
            ObituaryDateParser::parse('12.03.1938'),
            ObituaryDateParser::parse('04.09.2023'),
            new Place('Musterstadt'),
            'https://example.test/x',
            'example.test',
        );

        $engine = new MatchEngine();
        $result = $engine->score($candidate, $notice);
        $class  = (new Classifier())->classify($result, [$result]);

        // name 43 + birth 25 + place 12 + plausibility 10 = 90 (>= 85 -> Strong band).
        self::assertSame(90, $result->total);
        self::assertSame(Band::Strong, $class->band);
        self::assertSame('2023-09-04', $result->extractedFacts['deathDate']);
    }

    /**
     * A same-year, same-name namesake raises the birth-date hard conflict and the
     * 30-point penalty pushes the total below every positive band.
     */
    #[Test]
    public function sameYearNamesakeIsRejectedByPenalty(): void
    {
        // Person born 02.08.1962 (exact). Notice for a same-year, same-name namesake
        // born 27.08.1962 raises the birth-date hard conflict.
        $candidate = $this->rainerCandidate('X1965', '02.08.1962', []);
        $notice    = $this->rainerNotice('27.08.1962', 'https://example.test/namesake', null);

        $result = (new MatchEngine())->score($candidate, $notice);
        $class  = (new Classifier())->classify($result, [$result]);

        self::assertTrue($result->conflicts->hasHardConflict());

        // name 40 + plausibility 10 - 30 penalty = 20, which is below the Weak
        // threshold (40), so the namesake is rejected with the None band. (The
        // hard-conflict cap to Possible is exercised separately in ClassifierTest;
        // here the penalty alone already drops the score below any positive band.)
        self::assertSame(20, $result->total);
        self::assertSame(Band::None, $class->band);
    }

    /**
     * An identical exact birth date with matching name and place is a strong match
     * and raises no hard conflict.
     */
    #[Test]
    public function exactSameBirthDateIsAStrongMatch(): void
    {
        $candidate = $this->rainerCandidate('X1', '02.08.1962', [new Place('Musterstadt')]);
        $notice    = $this->rainerNotice('02.08.1962', 'https://example.test/ok', new Place('Musterstadt'));

        $result = (new MatchEngine())->score($candidate, $notice);
        $class  = (new Classifier())->classify($result, [$result]);

        self::assertFalse($result->conflicts->hasHardConflict());

        // name 40 + birth 30 + place 12 + plausibility 10 = 92 (>= 85 -> Strong band).
        self::assertSame(92, $result->total);
        self::assertSame(Band::Strong, $class->band);
    }

    /**
     * Assembling a ClassifiedMatch with a runner-up from a small candidate set
     * carries the runner-up, the hard-conflict flag and the per-signal maxima
     * through toArray().
     */
    #[Test]
    public function classifiedMatchSerialisesRunnerUpAndSignalMaxima(): void
    {
        $notice = $this->rainerNotice('02.08.1962', 'https://example.test/set', new Place('Musterstadt'));

        // The true subject: exact birth date, matching place -> a strong match.
        $subject = $this->rainerCandidate('X1', '02.08.1962', [new Place('Musterstadt')]);

        // A weaker namesake with the same name but a different birth year and no place.
        $namesake = new PersonCandidate(
            'X2',
            Gender::Male,
            new PersonName(['Rainer'], null, 'Vorbild', null),
            DateRange::year(1980),
            null,
            [],
            DateRange::unknown(),
        );

        $engine  = new MatchEngine();
        $results = array_map(
            static fn (PersonCandidate $candidate): MatchExplanation => $engine->score($candidate, $notice),
            [$subject, $namesake],
        );

        usort($results, static fn (MatchExplanation $a, MatchExplanation $b): int => $b->total <=> $a->total);

        $best   = $results[0];
        $runner = $results[1];

        $classification = (new Classifier())->classify($best, $results);

        $runnerUp = new RunnerUp(
            $runner->personId,
            $runner->total,
            (new Classifier())->classify($runner, $results)->band->value(),
            'Rainer Vorbild',
            1980,
            null,
        );

        $classified = new ClassifiedMatch($best, $classification, $runnerUp);
        $array      = $classified->toArray();

        self::assertSame('X1', $array['personId']);
        self::assertFalse($array['hardConflict']);

        $serialisedRunnerUp = $array['runnerUp'];
        self::assertIsArray($serialisedRunnerUp);
        self::assertSame('X2', $serialisedRunnerUp['personId']);
        self::assertSame($runner->total, $serialisedRunnerUp['score']);

        // The per-signal maxima are carried through the serialisation.
        $signals = $array['signals'];

        $name = $signals['name'];
        self::assertArrayHasKey('max', $name);
        self::assertSame(45, $name['max']);

        $birth = $signals['birth'];
        self::assertArrayHasKey('max', $birth);
        self::assertSame(30, $birth['max']);

        $place = $signals['place'];
        self::assertArrayHasKey('max', $place);
        self::assertSame(15, $place['max']);

        $plausibility = $signals['plausibility'];
        self::assertArrayHasKey('max', $plausibility);
        self::assertSame(10, $plausibility['max']);
    }
}
