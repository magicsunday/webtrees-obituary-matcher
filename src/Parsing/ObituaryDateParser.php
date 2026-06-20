<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Parsing;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use Throwable;

use function preg_match;
use function trim;

/**
 * Parses obituary date strings into a DateRange. GEDCOM parsing is NOT done here
 * (that is the webtrees adapter's job via core Date).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryDateParser
{
    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Parses an obituary date string into a date range.
     *
     * @param string|null $raw The raw date string from the notice.
     *
     * @return DateRange The parsed range (unknown when absent, invalid when unparseable).
     */
    public static function parse(?string $raw): DateRange
    {
        $value = trim($raw ?? '');

        if ($value === '') {
            return DateRange::unknown();
        }

        try {
            if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $value, $m) === 1) {
                return DateRange::exact(new DateValue((int) $m[3], (int) $m[2], (int) $m[1]), $value);
            }

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
                return DateRange::exact(new DateValue((int) $m[1], (int) $m[2], (int) $m[3]), $value);
            }

            if (preg_match('/^(\d{1,2})\.\s*(\d{4})$/', $value, $m) === 1) {
                $year  = (int) $m[2];
                $month = (int) $m[1];

                return DateRange::known(
                    new DateValue($year, $month, 1),
                    new DateValue($year, $month, DateValue::lastDayOfMonth($year, $month)),
                    DatePrecision::Month,
                    $value,
                );
            }

            if (preg_match('/^(\d{4})$/', $value, $m) === 1) {
                return DateRange::year((int) $m[1], $value);
            }
        } catch (Throwable) {
            return DateRange::invalid($value);
        }

        return DateRange::invalid($value);
    }
}
