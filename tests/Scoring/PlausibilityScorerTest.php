<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the plausibility scorer that sanity-checks a candidate against a notice.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PlausibilityScorer::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(Gender::class)]
#[UsesClass(ObituaryRecord::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class PlausibilityScorerTest extends TestCase
{
    /**
     * Builds a notice with the given exact death year.
     *
     * @param int $deathYear The year the notice records as the death year.
     *
     * @return ObituaryRecord
     */
    private function notice(int $deathYear): ObituaryRecord
    {
        return new ObituaryRecord(
            'Otto Vorbild',
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::unknown(),
            DateRange::exact(new DateValue($deathYear, 1, 1)),
            null,
            'https://example.test/x',
            'example.test',
        );
    }

    /**
     * A plausible elder candidate without a tree death date scores well.
     */
    #[Test]
    public function plausibleElderCandidateScoresWell(): void
    {
        $candidate = new PersonCandidate(
            'I1',
            Gender::Male,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown(),
        );

        $signal = (new PlausibilityScorer(new ScoreConfig()))->score($candidate, $this->notice(2023));

        self::assertGreaterThanOrEqual(8, $signal->score);
        self::assertContains('no death date in tree', $signal->reasons);
        self::assertContains('plausible age (85)', $signal->reasons);
        self::assertContains('gender consistent', $signal->reasons);
    }

    /**
     * The plausibility score never exceeds the configured cap.
     */
    #[Test]
    public function neverExceedsCap(): void
    {
        $candidate = new PersonCandidate(
            'I1',
            Gender::Male,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown(),
        );

        $signal = (new PlausibilityScorer(new ScoreConfig()))->score($candidate, $this->notice(2023));

        self::assertLessThanOrEqual(10, $signal->score);
        self::assertSame(10, $signal->max);
    }

    /**
     * An unknown birth date still credits the unknown tree death reason.
     */
    #[Test]
    public function unknownBirthStillCreditsTreeDeathUnknown(): void
    {
        $candidate = new PersonCandidate(
            'I1',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::unknown(),
            null,
            [],
            DateRange::unknown(),
        );

        $signal = (new PlausibilityScorer(new ScoreConfig()))->score($candidate, $this->notice(2023));

        self::assertContains('no death date in tree', $signal->reasons);
    }

    /**
     * An implausible age (over the upper bound) earns no plausible-age reason.
     */
    #[Test]
    public function implausibleAgeEarnsNoAgeReason(): void
    {
        $candidate = new PersonCandidate(
            'I1',
            Gender::Male,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::year(1800),
            null,
            [],
            DateRange::unknown(),
        );

        $signal = (new PlausibilityScorer(new ScoreConfig()))->score($candidate, $this->notice(2023));

        self::assertNotContains('plausible age (223)', $signal->reasons);
    }

    /**
     * A wide birth range whose maximum age exceeds the upper bound earns no plausible-age
     * point, even though its conservative (latest-endpoint) age stays within bounds.
     */
    #[Test]
    public function wideBirthRangeExceedingUpperBoundEarnsNoAgePoint(): void
    {
        // Birth between 1850 and 1950, death 2023: latest endpoint -> age 73 (plausible),
        // but earliest endpoint -> age 173 (> MAX_AGE 120), so the candidate must not score.
        $candidate = new PersonCandidate(
            'I1',
            Gender::Male,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::known(
                new DateValue(1850, 1, 1),
                new DateValue(1950, 12, 31),
                DatePrecision::Year,
            ),
            null,
            [],
            DateRange::unknown(),
        );

        $signal = (new PlausibilityScorer(new ScoreConfig()))->score($candidate, $this->notice(2023));

        foreach ($signal->reasons as $reason) {
            self::assertStringNotContainsString('plausible age', $reason);
        }
    }
}
