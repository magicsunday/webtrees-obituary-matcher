<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function checkdate;
use function preg_match;
use function sprintf;

/**
 * Converts an exact ISO `YYYY-MM-DD` death date into a GEDCOM `DD MON YYYY` string using a fixed,
 * locale-independent English month table. Only exact, real calendar dates are accepted — an
 * imprecise (`YYYY-MM`), year-only, or non-existent date (2023-02-31) is rejected, never coerced.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class GedcomDateConverter
{
    /**
     * The GEDCOM month abbreviations, indexed by month number (1–12).
     *
     * @var array<int, string>
     */
    private const array MONTHS = [
        1  => 'JAN',
        2  => 'FEB',
        3  => 'MAR',
        4  => 'APR',
        5  => 'MAY',
        6  => 'JUN',
        7  => 'JUL',
        8  => 'AUG',
        9  => 'SEP',
        10 => 'OCT',
        11 => 'NOV',
        12 => 'DEC',
    ];

    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Whether the value is an exact `YYYY-MM-DD` string AND a real calendar date.
     *
     * @param string|null $iso The candidate ISO date.
     *
     * @return bool True when the value is a valid exact day-level date.
     */
    public static function isValidExactIso(?string $iso): bool
    {
        if ($iso === null) {
            return false;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Converts an exact ISO `YYYY-MM-DD` date to GEDCOM `DD MON YYYY`.
     *
     * @param string $iso The exact ISO date.
     *
     * @return string The GEDCOM date.
     *
     * @throws MalformedDeathDateException When the value is not an exact calendar date.
     */
    public static function toGedcom(string $iso): string
    {
        if (
            (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) !== 1)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new MalformedDeathDateException(
                sprintf('Not an exact YYYY-MM-DD calendar date: %s', $iso)
            );
        }

        return sprintf('%02d %s %d', (int) $m[3], self::MONTHS[(int) $m[2]], (int) $m[1]);
    }
}
