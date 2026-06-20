<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Maps a webtrees {@see Date} (constructible without a tree, but needing the
 * calendar-date factory the bootstrap registers) to the engine's pure
 * {@see DateRange} and verifies the precision table against real `Date` output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(WebtreesDateMapper::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(DatePrecision::class)]
final class WebtreesDateMapperTest extends TestCase
{
    /**
     * Boot webtrees' DI container so {@see Date} can resolve the calendar-date
     * factory, and initialise I18N so month names format without notices.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * GEDCOM date string mapped to the precision the engine should record.
     *
     * The rows span every precision dimension {@see WebtreesDateMapper::precision()}
     * distinguishes: the leading-qualifier table (ABT/EST/CAL → Approximate, BET/FROM →
     * Interval) and the bounds-comparison fall-through (year-span → Interval, month-span →
     * Month, equal exact day → Exact, year-only → Year). The qualifier-less bounds branches
     * are reached through real qualifier-bearing ranges, since every multi-bound webtrees
     * {@see Date} carries a qualifier the table handles first.
     *
     * @return list<array{0:string,1:DatePrecision}>
     */
    public static function precisionCases(): array
    {
        return [
            ['12 MAR 1938', DatePrecision::Exact],
            ['MAR 1938', DatePrecision::Month],
            ['1938', DatePrecision::Year],
            ['ABT 1938', DatePrecision::Approximate],
            ['EST 1938', DatePrecision::Approximate],
            ['CAL 1938', DatePrecision::Approximate],
            ['BET 1936 AND 1940', DatePrecision::Interval],
            ['FROM 1936 TO 1940', DatePrecision::Interval],
            // A qualifier not in the table (BEF/AFT/INT) falls through to the bounds
            // comparison, which sees a single-year span and records a Year precision.
            ['BEF 1940', DatePrecision::Year],
            ['AFT 1936', DatePrecision::Year],
        ];
    }

    /**
     * Each GEDCOM date string maps to the expected DatePrecision value.
     */
    #[Test]
    #[DataProvider('precisionCases')]
    public function mapsPrecisionExactly(string $value, DatePrecision $expected): void
    {
        $range = WebtreesDateMapper::toRange(new Date($value));
        self::assertTrue($range->isKnown());
        self::assertSame($expected, $range->precision);
    }

    /**
     * A month-precision range covers only the days within that month and rejects dates in the following month.
     */
    #[Test]
    public function monthRangeHasTightBounds(): void
    {
        $range = WebtreesDateMapper::toRange(new Date('MAR 1938'));
        self::assertTrue($range->contains(new DateValue(1938, 3, 15)));
        self::assertFalse($range->contains(new DateValue(1938, 4, 1)));   // a Year mapper would wrongly accept this
    }

    /**
     * A BET interval range contains dates in the middle year and rejects dates outside the stated bounds.
     */
    #[Test]
    public function intervalSpansBothYears(): void
    {
        $range = WebtreesDateMapper::toRange(new Date('BET 1936 AND 1940'));
        self::assertTrue($range->contains(new DateValue(1937, 1, 1)));
        self::assertFalse($range->contains(new DateValue(1942, 1, 1)));
    }

    /**
     * Reversed BET..AND / FROM..TO source values whose lower bound is the later date.
     *
     * webtrees' {@see Date::isOK()} only checks both julian days are non-zero, never their
     * ordering, so a data-entry-error range like "BET 1940 AND 1936" arrives bounds-swapped
     * ({@see Date::minimumDate()} yields the later year). The mapper must normalise rather
     * than let {@see DateRange::known()} throw and crash the whole tree scan.
     *
     * @return list<array{0:string}>
     */
    public static function reversedRangeCases(): array
    {
        return [
            ['BET 1940 AND 1936'],
            ['FROM 1940 TO 1936'],
            ['BET MAR 1940 AND JAN 1940'],
        ];
    }

    /**
     * A reversed BET..AND / FROM..TO range is normalised to a known interval rather than crashing.
     */
    #[Test]
    #[DataProvider('reversedRangeCases')]
    public function reversedRangeIsNormalised(string $value): void
    {
        $range = WebtreesDateMapper::toRange(new Date($value));

        // No throw, and the swapped bounds now span the intended interval.
        self::assertTrue($range->isKnown());
        self::assertSame(DatePrecision::Interval, $range->precision);
    }

    /**
     * The reversed full-year interval "BET 1940 AND 1936" maps to the normalised 1936..1940 span.
     */
    #[Test]
    public function reversedYearIntervalNormalisesBounds(): void
    {
        $range = WebtreesDateMapper::toRange(new Date('BET 1940 AND 1936'));

        // 1938 lies inside the normalised [1936, 1940] interval; 1942 lies outside it.
        self::assertTrue($range->contains(new DateValue(1938, 1, 1)));
        self::assertFalse($range->contains(new DateValue(1942, 1, 1)));
        self::assertFalse($range->contains(new DateValue(1935, 1, 1)));
    }

    /**
     * An empty GEDCOM date string maps to an unknown range.
     */
    #[Test]
    public function emptyDateIsUnknown(): void
    {
        self::assertFalse(WebtreesDateMapper::toRange(new Date(''))->isKnown());
    }

    /**
     * A date with an unparseable year but a surviving qualifier maps to an invalid range carrying the reconstructed qualifier.
     */
    #[Test]
    public function uninterpretableValueWithSurvivingQualifierIsInvalid(): void
    {
        // The year token is unparseable, so webtrees reports the date as not OK; the leading
        // ABT qualifier still survives, so the raw value reconstructs non-empty and the
        // mapper records an Invalid range that round-trips the reconstructed value.
        $range = WebtreesDateMapper::toRange(new Date('ABT FOOBAR'));

        self::assertTrue($range->isInvalid());
        self::assertFalse($range->isKnown());
        self::assertSame('ABT', $range->original);
    }
}
