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
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\BirthScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the birth-date scorer that rewards matching birth dates by precision.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(BirthScorer::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class BirthScorerTest extends TestCase
{
    /**
     * Creates a birth scorer wired with the default scoring configuration.
     *
     * @return BirthScorer
     */
    private function scorer(): BirthScorer
    {
        return new BirthScorer(new ScoreConfig());
    }

    /**
     * Two equal exact dates score the full weight.
     */
    #[Test]
    public function bothExactAndEqual(): void
    {
        $date   = DateRange::exact(new DateValue(1962, 8, 2));
        $signal = $this->scorer()->score($date, $date);
        self::assertSame(30, $signal->score);
        self::assertContains('exact birth date', $signal->reasons);
    }

    /**
     * An exact notice date inside an approximate candidate range scores just below exact.
     */
    #[Test]
    public function noticeWithinApproximateRange(): void
    {
        $candidate = DateRange::known(new DateValue(1936, 1, 1), new DateValue(1940, 12, 31), DatePrecision::Approximate);
        $notice    = DateRange::exact(new DateValue(1938, 3, 12));
        self::assertSame(25, $this->scorer()->score($candidate, $notice)->score);
    }

    /**
     * Matching year-only dates score the year-level weight.
     */
    #[Test]
    public function sameYear(): void
    {
        self::assertSame(16, $this->scorer()->score(DateRange::year(1962), DateRange::year(1962))->score);
    }

    /**
     * An unknown date on either side scores zero with no reasons.
     */
    #[Test]
    public function unknownSideScoresZero(): void
    {
        $signal = $this->scorer()->score(DateRange::unknown(), DateRange::year(1962));
        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
    }

    /**
     * Matching month-and-year dates score the month-level weight.
     */
    #[Test]
    public function sameMonthAndYear(): void
    {
        $candidate = DateRange::known(new DateValue(1962, 8, 1), new DateValue(1962, 8, 31), DatePrecision::Month);
        $notice    = DateRange::known(new DateValue(1962, 8, 1), new DateValue(1962, 8, 31), DatePrecision::Month);
        $signal    = $this->scorer()->score($candidate, $notice);
        self::assertSame(22, $signal->score);
        self::assertContains('same month and year', $signal->reasons);
    }

    /**
     * Two approximate ranges sharing only the synthetic January lower bound must NOT be
     * rewarded as month-precise. The synthetic month=1 of an ABT/approximate range is not an
     * asserted month, so they fall through to the same-year branch when their years align.
     */
    #[Test]
    public function approximateRangesWithSyntheticJanuaryDoNotScoreSameMonthYear(): void
    {
        // Both lower bounds are the synthetic 1 January of their (matching) start year, but
        // neither side asserts a month; the windows span different multi-year ranges.
        $candidate = DateRange::known(new DateValue(1936, 1, 1), new DateValue(1940, 12, 31), DatePrecision::Approximate);
        $notice    = DateRange::known(new DateValue(1936, 1, 1), new DateValue(1942, 12, 31), DatePrecision::Approximate);
        $signal    = $this->scorer()->score($candidate, $notice);
        self::assertSame(16, $signal->score);
        self::assertContains('same year', $signal->reasons);
        self::assertNotContains('same month and year', $signal->reasons);
    }

    /**
     * Two differing exact dates score zero and never go negative.
     */
    #[Test]
    public function differentExactDatesScoreZeroNotNegative(): void
    {
        // A contradiction is detected elsewhere; the scorer must not go negative.
        $a = DateRange::exact(new DateValue(1962, 8, 2));
        $b = DateRange::exact(new DateValue(1962, 8, 27));
        self::assertSame(0, $this->scorer()->score($a, $b)->score);
    }
}
