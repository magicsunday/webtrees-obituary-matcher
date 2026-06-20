<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Parsing;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ObituaryDateParser across exact, partial, empty and invalid date strings.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryDateParser::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
final class ObituaryDateParserTest extends TestCase
{
    /**
     * A German DD.MM.YYYY date parses into an exact range.
     */
    #[Test]
    public function parsesGermanFullDate(): void
    {
        $range = ObituaryDateParser::parse('02.08.1962');
        self::assertTrue($range->isExact());
        self::assertNotNull($range->earliest);
        self::assertSame(19620802, $range->earliest->comparable());
    }

    /**
     * Single-digit day and month components still parse into an exact range.
     */
    #[Test]
    public function parsesSingleDigitDayMonth(): void
    {
        self::assertTrue(ObituaryDateParser::parse('2.8.1962')->isExact());
    }

    /**
     * An ISO YYYY-MM-DD date parses into an exact range.
     */
    #[Test]
    public function parsesIsoDate(): void
    {
        self::assertTrue(ObituaryDateParser::parse('1962-08-02')->isExact());
    }

    /**
     * A year-only string yields a known, non-exact range covering that year.
     */
    #[Test]
    public function parsesYearOnly(): void
    {
        $range = ObituaryDateParser::parse('1962');
        self::assertTrue($range->isKnown());
        self::assertFalse($range->isExact());
        self::assertTrue($range->contains(new DateValue(1962, 6, 1)));
    }

    /**
     * A month/year string yields a known, non-exact range bounded to that month.
     */
    #[Test]
    public function parsesMonthOnly(): void
    {
        $range = ObituaryDateParser::parse('08.1962');

        self::assertTrue($range->isKnown());
        self::assertFalse($range->isExact());
        self::assertTrue($range->contains(new DateValue(1962, 8, 15)));
        self::assertFalse($range->contains(new DateValue(1962, 7, 31)));
        self::assertFalse($range->contains(new DateValue(1962, 9, 1)));
    }

    /**
     * A German full date with spaces after the dots still parses into an exact range.
     */
    #[Test]
    public function parsesGermanFullDateWithSpacedDots(): void
    {
        $range = ObituaryDateParser::parse('02. 08. 1962');
        self::assertTrue($range->isExact());
        self::assertNotNull($range->earliest);
        self::assertSame(19620802, $range->earliest->comparable());
    }

    /**
     * A month/year string with a space after the dot yields the same month range.
     */
    #[Test]
    public function parsesMonthOnlyWithSpacedDot(): void
    {
        $range = ObituaryDateParser::parse('08. 1962');

        self::assertTrue($range->isKnown());
        self::assertFalse($range->isExact());
        self::assertTrue($range->contains(new DateValue(1962, 8, 15)));
        self::assertFalse($range->contains(new DateValue(1962, 9, 1)));
    }

    /**
     * A null or empty input yields an unknown, non-invalid range.
     */
    #[Test]
    public function emptyIsUnknown(): void
    {
        self::assertFalse(ObituaryDateParser::parse(null)->isKnown());
        self::assertFalse(ObituaryDateParser::parse('')->isInvalid());
    }

    /**
     * An unparseable string yields an invalid range.
     */
    #[Test]
    public function garbageIsInvalid(): void
    {
        self::assertTrue(ObituaryDateParser::parse('not a date')->isInvalid());
    }
}
