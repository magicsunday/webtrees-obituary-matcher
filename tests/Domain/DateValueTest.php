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
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DateValue value object: comparable encoding and calendar validation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DateValue::class)]
final class DateValueTest extends TestCase
{
    /**
     * Verifies that a fully-specified date encodes as YYYYMMDD.
     */
    #[Test]
    public function comparableEncodesYearMonthDay(): void
    {
        self::assertSame(19620802, (new DateValue(1962, 8, 2))->comparable());
    }

    /**
     * Verifies that missing month and day default to 1 in the comparable integer.
     */
    #[Test]
    public function comparableFillsMissingPartsWithOne(): void
    {
        self::assertSame(19620101, (new DateValue(1962))->comparable());
    }

    /**
     * Verifies that a day that does not exist in the given month raises
     * an InvalidArgumentException.
     */
    #[Test]
    public function rejectsImpossibleCalendarDate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateValue(2020, 2, 31);
    }

    /**
     * Verifies that specifying a day without a month raises an InvalidArgumentException.
     */
    #[Test]
    public function rejectsDayWithoutMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateValue(2020, null, 5);
    }

    /**
     * Verifies that lastDayOfMonth returns the correct last day, including leap-year February.
     */
    #[Test]
    public function lastDayOfMonthHonoursLeapYears(): void
    {
        self::assertSame(31, DateValue::lastDayOfMonth(2023, 1));
        self::assertSame(30, DateValue::lastDayOfMonth(2023, 4));
        self::assertSame(28, DateValue::lastDayOfMonth(2023, 2));
        self::assertSame(29, DateValue::lastDayOfMonth(2024, 2));
    }
}
