<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use DateTimeImmutable;
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
     * @param int $year  Four-digit year.
     * @param int $month Month 1..12.
     *
     * @return int The last day of that month (28..31).
     */
    public static function lastDayOfMonth(int $year, int $month): int
    {
        return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->format('t');
    }
}
