<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

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
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\BirthScorer;
use MagicSunday\ObituaryMatcher\Scoring\ConflictDetector;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Scoring\NameScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlaceScorer;
use MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\KoelnerPhonetik;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the engine that aggregates the four positive scorers and the conflict detector.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(MatchEngine::class)]
#[UsesClass(BirthScorer::class)]
#[UsesClass(ConflictDetector::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(GivenNameVariants::class)]
#[UsesClass(KoelnerPhonetik::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(NameScorer::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(ObituaryRecord::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(PlaceScorer::class)]
#[UsesClass(PlausibilityScorer::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class MatchEngineTest extends TestCase
{
    /**
     * A strong match sums all four positive signals and harvests the exact death date.
     */
    #[Test]
    public function scoresStrongMatchAndExtractsDeath(): void
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
            new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller'),
            DateRange::exact(new DateValue(1938, 3, 12)),
            DateRange::exact(new DateValue(2023, 9, 4)),
            new Place('Musterstadt'),
            'https://example.test/x',
            'example.test',
        );

        $result = (new MatchEngine())->score($candidate, $notice);

        // name 43 + birth 25 + place 12 + plausibility (3+2+5 cap 10) = 90.
        self::assertSame(90, $result->total);
        self::assertSame('2023-09-04', $result->extractedFacts['deathDate']);
    }

    /**
     * Two exact differing birth dates raise a hard conflict and subtract the penalty.
     */
    #[Test]
    public function birthConflictSubtractsPenalty(): void
    {
        $candidate = new PersonCandidate(
            'I9',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::exact(new DateValue(1962, 8, 2)),
            null,
            [],
            DateRange::unknown(),
        );

        $notice = new ObituaryRecord(
            'Otto Vorbild',
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::exact(new DateValue(1962, 8, 27)),
            DateRange::exact(new DateValue(2026, 3, 23)),
            null,
            'https://example.test/y',
            'example.test',
        );

        $result = (new MatchEngine())->score($candidate, $notice);

        self::assertTrue($result->conflicts->hasHardConflict());

        // name full exact 40 + plausibility (death unknown 3 + plausible age 5 = 8,
        // no gender bonus since Unknown), minus 30 penalty = 18.
        self::assertSame(18, $result->total);
    }

    /**
     * A pre-clamp negative total (no positive signals, capped penalty) clamps to zero.
     */
    #[Test]
    public function totalClampsNegativeToZero(): void
    {
        $candidate = new PersonCandidate(
            'I9',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::exact(new DateValue(1900, 1, 1)),
            null,
            [],
            DateRange::exact(new DateValue(1950, 1, 1)),
        );

        $notice = new ObituaryRecord(
            'Frieda Musterfrau',
            new PersonName(['Frieda'], null, 'Musterfrau', null),
            DateRange::exact(new DateValue(1962, 8, 27)),
            DateRange::exact(new DateValue(2026, 3, 23)),
            null,
            'https://example.test/z',
            'example.test',
        );

        // No signal agrees (0 positive) and the capped penalty is 50, so the
        // pre-clamp total is -50 and the clamp engages to 0.
        self::assertSame(0, (new MatchEngine())->score($candidate, $notice)->total);
    }
}
