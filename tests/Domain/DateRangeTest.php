<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DateRange value object: construction, exactness, containment and overlap.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRangeStatus::class)]
final class DateRangeTest extends TestCase
{
    /**
     * Verifies that an unknown range is neither known nor invalid.
     */
    #[Test]
    public function unknownIsNotKnown(): void
    {
        self::assertFalse(DateRange::unknown()->isKnown());
        self::assertFalse(DateRange::unknown()->isInvalid());
    }

    /**
     * Verifies that an invalid range reports itself as invalid but not as known.
     */
    #[Test]
    public function invalidReportsItself(): void
    {
        self::assertTrue(DateRange::invalid('foo')->isInvalid());
        self::assertFalse(DateRange::invalid('foo')->isKnown());
    }

    /**
     * Verifies that an exact range is detected as exact while a year range is not.
     */
    #[Test]
    public function exactIsExact(): void
    {
        self::assertTrue(DateRange::exact(new DateValue(1962, 8, 2))->isExact());
        self::assertFalse(DateRange::year(1962)->isExact());
    }

    /**
     * Verifies that a year-only DateValue wrapped with Exact precision is NOT reported
     * as exact, while a fully-specified day still is. The comparable() value defaults a
     * missing month/day to 1, so isExact() must additionally require a non-null month/day.
     */
    #[Test]
    public function yearOnlyValueWithExactPrecisionIsNotExact(): void
    {
        self::assertFalse(DateRange::exact(new DateValue(1962))->isExact());
        self::assertFalse(DateRange::exact(new DateValue(1962, 8))->isExact());
        self::assertTrue(DateRange::exact(new DateValue(1962, 8, 2))->isExact());
    }

    /**
     * Verifies that a whole-year range contains a day within that year
     * but not a day in a different year.
     */
    #[Test]
    public function yearRangeContainsADayInThatYear(): void
    {
        self::assertTrue(DateRange::year(1962)->contains(new DateValue(1962, 8, 2)));
        self::assertFalse(DateRange::year(1962)->contains(new DateValue(1963, 1, 1)));
    }

    /**
     * Verifies that two ranges sharing a single boundary day are considered overlapping.
     */
    #[Test]
    public function overlapsAtTheEdge(): void
    {
        $a = DateRange::known(new DateValue(1960, 1, 1), new DateValue(1962, 12, 31), DatePrecision::Interval);
        $b = DateRange::year(1962);

        self::assertTrue($a->overlaps($b));
    }

    /**
     * Verifies that two ranges with no day in common are not considered overlapping.
     */
    #[Test]
    public function doesNotOverlapWhenDisjoint(): void
    {
        self::assertFalse(DateRange::year(1960)->overlaps(DateRange::year(1962)));
    }

    /**
     * Verifies that an unknown range never overlaps with any other range.
     */
    #[Test]
    public function unknownNeverOverlaps(): void
    {
        self::assertFalse(DateRange::unknown()->overlaps(DateRange::year(1962)));
        self::assertFalse(DateRange::year(1962)->overlaps(DateRange::unknown()));
    }

    /**
     * Verifies that constructing a range where the earliest date is after the latest
     * date raises an InvalidArgumentException.
     */
    #[Test]
    public function rejectsEarliestAfterLatest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRange::known(
            new DateValue(1970, 1, 1),
            new DateValue(1960, 1, 1),
            DatePrecision::Interval,
        );
    }
}
