<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use InvalidArgumentException;

use function checkdate;
use function sprintf;

/**
 * A single calendar date with optional month/day, validated on construction.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DateValue
{
    /**
     * @var array<int, int> Days per month keyed by month number (1..12); February is
     *                      computed separately to honour leap years.
     */
    private const array DAYS_IN_MONTH = [
        1  => 31,
        2  => 28,
        3  => 31,
        4  => 30,
        5  => 31,
        6  => 30,
        7  => 31,
        8  => 31,
        9  => 30,
        10 => 31,
        11 => 30,
        12 => 31,
    ];

    /**
     * Constructor.
     *
     * @param int      $year  Four-digit year.
     * @param int|null $month Month 1..12, or null when unknown.
     * @param int|null $day   Day valid for the month/year, or null when unknown.
     *
     * @throws InvalidArgumentException When the date is not a real calendar date.
     */
    public function __construct(
        public int $year,
        public ?int $month = null,
        public ?int $day = null,
    ) {
        if (
            ($this->day !== null)
            && ($this->month === null)
        ) {
            throw new InvalidArgumentException('Day given without a month.');
        }

        if (
            ($this->month !== null)
            && (($this->month < 1) || ($this->month > 12))
        ) {
            throw new InvalidArgumentException(sprintf('Invalid month: %d', $this->month));
        }

        if (
            ($this->day !== null)
            && !checkdate($this->month, $this->day, $this->year)
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid date: %04d-%02d-%02d', $this->year, $this->month, $this->day)
            );
        }
    }

    /**
     * Sortable yyyymmdd key; missing month/day default to 1.
     *
     * @return int
     */
    public function comparable(): int
    {
        return ($this->year * 10000) + (($this->month ?? 1) * 100) + ($this->day ?? 1);
    }

    /**
     * Returns the last calendar day of the given month, honouring leap years.
     *
     * Uses pure arithmetic to avoid the overhead and edge-case year limits of
     * DateTimeImmutable.
     *
     * @param int $year  Four-digit year.
     * @param int $month Month 1..12.
     *
     * @return int The last day of that month (28..31).
     */
    public static function lastDayOfMonth(int $year, int $month): int
    {
        if ($month === 2) {
            $isLeapYear = (($year % 4 === 0) && ($year % 100 !== 0))
                || ($year % 400 === 0);

            return $isLeapYear ? 29 : 28;
        }

        return self::DAYS_IN_MONTH[$month] ?? 31;
    }
}
