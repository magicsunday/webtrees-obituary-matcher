<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Support\MalformedDeathDateException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the ISO → GEDCOM death-date conversion.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(GedcomDateConverter::class)]
final class GedcomDateConverterTest extends TestCase
{
    /**
     * Exact, real calendar dates convert to GEDCOM, locale-independently.
     *
     * @param string $iso      The ISO input.
     * @param string $expected The GEDCOM output.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('exactDates')]
    public function convertsExactIsoToGedcom(string $iso, string $expected): void
    {
        self::assertSame($expected, GedcomDateConverter::toGedcom($iso));
        self::assertTrue(GedcomDateConverter::isValidExactIso($iso));
    }

    /**
     * Every-month + boundary cases.
     *
     * @return array<string, array{string, string}>
     */
    public static function exactDates(): array
    {
        return [
            'sep'      => ['2023-09-04', '04 SEP 2023'],
            'dec-31'   => ['2023-12-31', '31 DEC 2023'],
            'jan-01'   => ['2024-01-01', '01 JAN 2024'],
            'feb-leap' => ['2024-02-29', '29 FEB 2024'],
            'nov'      => ['1900-11-15', '15 NOV 1900'],
        ];
    }

    /**
     * Malformed or non-calendar inputs are rejected by both methods.
     *
     * @param string|null $iso The bad input.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('badDates')]
    public function rejectsAnythingThatIsNotAnExactCalendarDate(?string $iso): void
    {
        self::assertFalse(GedcomDateConverter::isValidExactIso($iso));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function badDates(): array
    {
        return [
            'null'        => [null],
            'empty'       => [''],
            'month-only'  => ['2023-09'],
            'year-only'   => ['2023'],
            'german'      => ['04.09.2023'],
            'month-13'    => ['2023-13-01'],
            'day-00'      => ['2023-09-00'],
            'feb-31'      => ['2023-02-31'],
            'non-leap-29' => ['2023-02-29'],
            'trailing-nl' => ["2023-09-04\n"],
        ];
    }

    /**
     * toGedcom throws on a non-exact date.
     *
     * @return void
     */
    #[Test]
    public function toGedcomThrowsOnFebThirtyFirst(): void
    {
        $this->expectException(MalformedDeathDateException::class);

        GedcomDateConverter::toGedcom('2023-02-31');
    }
}
