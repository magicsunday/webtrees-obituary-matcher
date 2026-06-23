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
        return self::parseExactIso($iso) !== null;
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
        $parts = self::parseExactIso($iso);

        if ($parts === null) {
            throw new MalformedDeathDateException(
                sprintf('Not an exact YYYY-MM-DD calendar date: %s', $iso)
            );
        }

        // GEDCOM 5.5.1 writes the day WITHOUT a leading zero (`4 SEP 2023`, not `04 SEP 2023`), matching
        // webtrees' own canonical day form, so a later re-serialisation never shifts the stored fact id.
        return sprintf('%d %s %d', (int) $parts[3], self::MONTHS[(int) $parts[2]], (int) $parts[1]);
    }

    /**
     * Parses an exact `YYYY-MM-DD` value into its `[full, year, month, day]` match, or null when the
     * value is not a syntactically exact AND real calendar date. The single validation seam both public
     * methods share, so the accepted-date contract can never drift between the check and the conversion.
     *
     * @param string|null $iso The candidate ISO date.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null The regex match, or null when invalid.
     */
    private static function parseExactIso(?string $iso): ?array
    {
        if ($iso === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) !== 1) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m;
    }
}
