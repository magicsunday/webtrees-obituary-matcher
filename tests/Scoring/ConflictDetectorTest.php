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
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Scoring\ConflictDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the conflict detector that is the sole source of scoring penalties.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ConflictDetector::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(Gender::class)]
#[UsesClass(ObituaryRecord::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(ScoreConfig::class)]
final class ConflictDetectorTest extends TestCase
{
    /**
     * Builds a candidate with the given birth and (optional) death ranges.
     *
     * @param DateRange      $birth The candidate's birth range.
     * @param DateRange|null $death The candidate's death range, or null for unknown.
     *
     * @return PersonCandidate
     */
    private function candidate(DateRange $birth, ?DateRange $death = null): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            $birth,
            null,
            [],
            $death ?? DateRange::unknown(),
        );
    }

    /**
     * Builds a notice with the given birth and death ranges.
     *
     * @param DateRange $birth The notice's birth range.
     * @param DateRange $death The notice's death range.
     *
     * @return ObituaryRecord
     */
    private function notice(DateRange $birth, DateRange $death): ObituaryRecord
    {
        return new ObituaryRecord(
            'Otto Vorbild',
            new PersonName(['Otto'], null, 'Vorbild', null),
            $birth,
            $death,
            null,
            'https://example.test/x',
            'example.test',
        );
    }

    /**
     * Builds an approximate birth range spanning whole years (the ABT shape from webtrees).
     *
     * @param int $fromYear The inclusive lower-bound year.
     * @param int $toYear   The inclusive upper-bound year.
     *
     * @return DateRange The approximate range.
     */
    private function approximateYears(int $fromYear, int $toYear): DateRange
    {
        return DateRange::known(
            new DateValue($fromYear, 1, 1),
            new DateValue($toYear, 12, 31),
            DatePrecision::Approximate,
        );
    }

    /**
     * Asserts the result is a single hard birth-date conflict with the given rendered values.
     *
     * @param ConflictResult $result        The detection result.
     * @param string         $treeValue     The expected rendered tree value.
     * @param string         $obituaryValue The expected rendered obituary value.
     *
     * @return void
     */
    private function assertHardBirthConflict(ConflictResult $result, string $treeValue, string $obituaryValue): void
    {
        self::assertTrue($result->hasHardConflict());
        self::assertSame(30, $result->penalty);
        self::assertSame('birth date', $result->reasons[0]->field);
        self::assertSame(ConflictSeverity::Hard, $result->reasons[0]->severity);
        self::assertSame($treeValue, $result->reasons[0]->treeValue);
        self::assertSame($obituaryValue, $result->reasons[0]->obituaryValue);
    }

    /**
     * Two exact but differing birth dates are a hard conflict with the field values.
     */
    #[Test]
    public function birthExactDifferentIsHardConflict(): void
    {
        $candidate = $this->candidate(DateRange::exact(new DateValue(1962, 8, 2)));
        $notice    = $this->notice(DateRange::exact(new DateValue(1962, 8, 27)), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        $this->assertHardBirthConflict($result, '1962-08-02', '1962-08-27');
    }

    /**
     * Two identical exact birth dates raise no conflict.
     */
    #[Test]
    public function sameExactBirthIsNoConflict(): void
    {
        $candidate = $this->candidate(DateRange::exact(new DateValue(1962, 8, 2)));
        $notice    = $this->notice(DateRange::exact(new DateValue(1962, 8, 2)), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
        self::assertSame([], $result->reasons);
    }

    /**
     * A certain death date differing from the notice is a hard conflict.
     */
    #[Test]
    public function certainDifferentDeathIsHardConflict(): void
    {
        $candidate = $this->candidate(DateRange::unknown(), DateRange::exact(new DateValue(1980, 1, 1)));
        $notice    = $this->notice(DateRange::unknown(), DateRange::exact(new DateValue(2023, 9, 4)));
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertTrue($result->hasHardConflict());
        self::assertSame(30, $result->penalty);
        self::assertSame('death date', $result->reasons[0]->field);
        self::assertSame('1980-01-01', $result->reasons[0]->treeValue);
        self::assertSame('2023-09-04', $result->reasons[0]->obituaryValue);
    }

    /**
     * Two known but non-overlapping birth year ranges are a hard conflict even though
     * neither side is an exact single day.
     */
    #[Test]
    public function nonOverlappingBirthYearsAreHardConflict(): void
    {
        $candidate = $this->candidate(DateRange::year(1930));
        $notice    = $this->notice(DateRange::year(1940), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        $this->assertHardBirthConflict($result, '1930', '1940');
    }

    /**
     * Two overlapping approximate ranges (ABT 1938 = 1936..1940 vs 1940) raise no conflict.
     */
    #[Test]
    public function overlappingApproximateBirthRangesAreNoConflict(): void
    {
        $candidate = $this->candidate($this->approximateYears(1936, 1940));
        $notice    = $this->notice(DateRange::year(1940), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
    }

    /**
     * An approximate range that does not overlap an exact day is a hard conflict and the
     * reason renders the year-range against the ISO exact date.
     */
    #[Test]
    public function nonOverlappingApproximateBirthRangeIsHardConflict(): void
    {
        $candidate = $this->candidate($this->approximateYears(1936, 1940));
        $notice    = $this->notice(DateRange::year(1960), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        $this->assertHardBirthConflict($result, '1936-1940', '1960');
    }

    /**
     * An approximate birth range overlapping an exact day raises no conflict: ABT 1938
     * (1936..1940) against the exact 12.03.1938 must stay a clean match.
     */
    #[Test]
    public function approximateRangeOverlappingExactDayIsNoConflict(): void
    {
        $candidate = $this->candidate($this->approximateYears(1936, 1940));
        $notice    = $this->notice(DateRange::exact(new DateValue(1938, 3, 12)), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
    }

    /**
     * A forward-search candidate without a death date raises no death conflict.
     */
    #[Test]
    public function forwardCandidateWithoutDeathHasNoDeathConflict(): void
    {
        $candidate = $this->candidate(DateRange::year(1938), DateRange::unknown());
        $notice    = $this->notice(DateRange::year(1938), DateRange::exact(new DateValue(2023, 9, 4)));
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
    }

    /**
     * A candidate too young at the notice's death is a hard age conflict.
     */
    #[Test]
    public function implausiblyYoungIsHardConflict(): void
    {
        $candidate = $this->candidate(DateRange::year(2020));
        $notice    = $this->notice(DateRange::unknown(), DateRange::exact(new DateValue(2023, 9, 4)));
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertTrue($result->hasHardConflict());
        self::assertSame(20, $result->penalty);
        self::assertSame('age', $result->reasons[0]->field);
        self::assertSame('2020', $result->reasons[0]->treeValue);
        self::assertSame('2023', $result->reasons[0]->obituaryValue);
    }

    /**
     * A candidate born far too early to be the deceased (age above the plausible cap)
     * is a hard age conflict; the band is capped by the penalty.
     */
    #[Test]
    public function implausiblyOldIsHardConflict(): void
    {
        // Born 1850, notice death 2020 -> maximum age 170, far above the 120-year cap.
        $candidate = $this->candidate(DateRange::year(1850));
        $notice    = $this->notice(DateRange::unknown(), DateRange::exact(new DateValue(2020, 1, 1)));
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertTrue($result->hasHardConflict());
        self::assertSame(20, $result->penalty);
        self::assertSame('age', $result->reasons[0]->field);
        self::assertSame(ConflictSeverity::Hard, $result->reasons[0]->severity);
        self::assertSame('1850', $result->reasons[0]->treeValue);
        self::assertSame('2020', $result->reasons[0]->obituaryValue);
    }

    /**
     * A normal lifespan (1938 birth, 2023 death -> age 85) raises no age conflict.
     */
    #[Test]
    public function normalAgeIsNoConflict(): void
    {
        $candidate = $this->candidate(DateRange::year(1938));
        $notice    = $this->notice(DateRange::unknown(), DateRange::exact(new DateValue(2023, 9, 4)));
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
        self::assertSame([], $result->reasons);
    }

    /**
     * An unparseable date on a side yields a soft conflict with no penalty.
     */
    #[Test]
    public function invalidDateIsSoftConflictWithoutPenalty(): void
    {
        $candidate = $this->candidate(DateRange::invalid('32. Hornung 1962'));
        $notice    = $this->notice(DateRange::unknown(), DateRange::unknown());
        $result    = (new ConflictDetector(new ScoreConfig()))->detect($candidate, $notice);

        self::assertFalse($result->hasHardConflict());
        self::assertSame(0, $result->penalty);
        self::assertSame('birth date', $result->reasons[0]->field);
        self::assertSame(ConflictSeverity::Soft, $result->reasons[0]->severity);
        self::assertSame('(uninterpretable)', $result->reasons[0]->treeValue);
    }

    /**
     * The penalty is capped at the configured maximum.
     */
    #[Test]
    public function penaltyIsCappedAtMaximum(): void
    {
        $candidate = $this->candidate(
            DateRange::exact(new DateValue(1962, 8, 2)),
            DateRange::exact(new DateValue(1980, 1, 1)),
        );
        $notice = $this->notice(
            DateRange::exact(new DateValue(1962, 8, 27)),
            DateRange::exact(new DateValue(2023, 9, 4)),
        );
        $result = (new ConflictDetector(new ScoreConfig(maxPenalty: 50)))->detect($candidate, $notice);

        self::assertSame(50, $result->penalty);
    }
}
