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

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * A date as an interval with a precision and a status.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DateRange
{
    /**
     * Constructor.
     *
     * @param DateValue|null  $earliest  Lower bound, or null for an open range.
     * @param DateValue|null  $latest    Upper bound, or null for an open range.
     * @param DatePrecision   $precision Precision marker.
     * @param DateRangeStatus $status    Whether the range carries a usable value.
     * @param string|null     $original  Raw source string, if any.
     *
     * @throws InvalidArgumentException When earliest is after latest.
     */
    private function __construct(
        public ?DateValue $earliest,
        public ?DateValue $latest,
        public DatePrecision $precision,
        public DateRangeStatus $status,
        public ?string $original = null,
    ) {
        if (
            ($earliest instanceof DateValue)
            && ($latest instanceof DateValue)
            && ($earliest->comparable() > $latest->comparable())
        ) {
            throw new InvalidArgumentException('Earliest date must not be after latest date.');
        }
    }

    /**
     * Creates a known range with explicit bounds.
     *
     * @param DateValue     $earliest  Lower bound.
     * @param DateValue     $latest    Upper bound.
     * @param DatePrecision $precision Precision marker.
     * @param string|null   $original  Raw source string, if any.
     *
     * @return self
     */
    public static function known(
        DateValue $earliest,
        DateValue $latest,
        DatePrecision $precision,
        ?string $original = null,
    ): self {
        return new self($earliest, $latest, $precision, DateRangeStatus::Known, $original);
    }

    /**
     * Creates an exact single-day range.
     *
     * @param DateValue   $date     The exact day.
     * @param string|null $original Raw source string, if any.
     *
     * @return self
     */
    public static function exact(DateValue $date, ?string $original = null): self
    {
        return new self($date, $date, DatePrecision::Exact, DateRangeStatus::Known, $original);
    }

    /**
     * Creates a whole-year range spanning January 1 to December 31.
     *
     * @param int         $year     Four-digit year.
     * @param string|null $original Raw source string, if any.
     *
     * @return self
     */
    public static function year(int $year, ?string $original = null): self
    {
        return new self(
            new DateValue($year, 1, 1),
            new DateValue($year, 12, 31),
            DatePrecision::Year,
            DateRangeStatus::Known,
            $original,
        );
    }

    /**
     * Creates a range representing an absent date.
     *
     * @return self
     */
    public static function unknown(): self
    {
        return new self(null, null, DatePrecision::Open, DateRangeStatus::Unknown);
    }

    /**
     * Creates a range representing an unparseable date.
     *
     * @param string $original The unparseable source string.
     *
     * @return self
     */
    public static function invalid(string $original): self
    {
        return new self(null, null, DatePrecision::Open, DateRangeStatus::Invalid, $original);
    }

    /**
     * Returns whether the range carries a usable value.
     *
     * @return bool
     */
    public function isKnown(): bool
    {
        return $this->status === DateRangeStatus::Known;
    }

    /**
     * Returns whether a date string was present but could not be parsed.
     *
     * @return bool
     */
    public function isInvalid(): bool
    {
        return $this->status === DateRangeStatus::Invalid;
    }

    /**
     * Returns whether this is a fully-known single day.
     *
     * @return bool
     */
    public function isExact(): bool
    {
        return $this->isKnown()
            && ($this->precision === DatePrecision::Exact)
            && ($this->earliest instanceof DateValue)
            && ($this->latest instanceof DateValue)
            && ($this->earliest->month !== null)
            && ($this->earliest->day !== null)
            && ($this->latest->month !== null)
            && ($this->latest->day !== null)
            && ($this->earliest->comparable() === $this->latest->comparable());
    }

    /**
     * Returns whether the two known ranges intersect.
     *
     * @param self $other The range to test against.
     *
     * @return bool
     */
    public function overlaps(self $other): bool
    {
        if (
            !$this->isKnown()
            || !$other->isKnown()
        ) {
            return false;
        }

        return ($this->lowerBound() <= $other->upperBound())
            && ($other->lowerBound() <= $this->upperBound());
    }

    /**
     * Returns whether the day falls within this known range.
     *
     * @param DateValue $date The day to test.
     *
     * @return bool
     */
    public function contains(DateValue $date): bool
    {
        if (!$this->isKnown()) {
            return false;
        }

        $key = $date->comparable();

        return ($key >= $this->lowerBound()) && ($key <= $this->upperBound());
    }

    /**
     * Returns the lower comparable bound (PHP_INT_MIN when open).
     *
     * @return int
     */
    private function lowerBound(): int
    {
        return $this->earliest?->comparable() ?? PHP_INT_MIN;
    }

    /**
     * Returns the upper comparable bound (PHP_INT_MAX when open).
     *
     * @return int
     */
    private function upperBound(): int
    {
        if (!$this->latest instanceof DateValue) {
            return PHP_INT_MAX;
        }

        // Fill a missing day with the last day of the month so an upper bound
        // never excludes valid days late in the month.
        $day = $this->latest->day;

        if (
            ($day === null)
            && ($this->latest->month !== null)
        ) {
            $day = DateValue::lastDayOfMonth($this->latest->year, $this->latest->month);
        }

        return ($this->latest->year * 10000) + (($this->latest->month ?? 12) * 100) + ($day ?? 31);
    }
}
