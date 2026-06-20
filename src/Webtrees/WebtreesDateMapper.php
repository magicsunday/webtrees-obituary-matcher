<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Date\AbstractCalendarDate;
use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;

use function ltrim;
use function preg_replace;
use function str_starts_with;
use function strtoupper;
use function trim;

/**
 * Translates a webtrees {@see Date} into the engine's pure {@see DateRange}.
 *
 * This adapter is the only place in the package allowed to depend on the
 * `Fisharebest\Webtrees` namespace; the scoring engine itself stays free of any
 * webtrees coupling so it can be unit-tested without a tree.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class WebtreesDateMapper
{
    /**
     * Static-only utility; never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Maps a webtrees date to a {@see DateRange}.
     *
     * The webtrees {@see Date} does not keep the source GEDCOM string, so the raw
     * value is reconstructed from the public qualifiers ({@see Date::$qual1} /
     * {@see Date::$qual2}) and the calendar-neutral GEDCOM reformatting extensions
     * of {@see AbstractCalendarDate::format()} — the same `%A %O %E` tokens the
     * webtrees importer uses to populate the raw `d_*` columns. This yields the
     * un-localised value the precision rules below depend on, never the
     * HTML-wrapped, locale-formatted {@see Date::display()} output.
     *
     * @param Date $d The webtrees date to convert.
     *
     * @return DateRange The equivalent engine range (unknown when absent).
     */
    public static function toRange(Date $d): DateRange
    {
        $raw = self::rawValue($d);

        if (trim($raw) === '') {
            return DateRange::unknown();
        }

        if (!$d->isOK()) {
            return DateRange::invalid($raw);
        }

        $minimum  = $d->minimumDate();
        $maximum  = $d->maximumDate();
        $earliest = self::toDateValue($minimum);
        $latest   = self::toDateValue($maximum);

        // webtrees' isOK() does not enforce min <= max, so a reversed BET..AND / FROM..TO
        // (e.g. "BET 1940 AND 1936") arrives bounds-swapped. Normalise rather than letting
        // DateRange::known() throw and crash the whole tree scan. The reversal is detected on
        // webtrees' own julian-day ordering — the lower bound's earliest day against the upper
        // bound's latest day — so a legitimately less-precise upper bound ("FROM FEB 2023 TO
        // 2023", whose latest day is still December) is never mistaken for a reversed range.
        if ($minimum->minimumJulianDay() > $maximum->maximumJulianDay()) {
            [$earliest, $latest] = [$latest, $earliest];
            [$minimum, $maximum] = [$maximum, $minimum];
        }

        return DateRange::known(
            $earliest,
            $latest,
            self::precision($raw, $minimum, $maximum),
            $raw,
        );
    }

    /**
     * Reconstructs the GEDCOM value the date was built from, qualifier first.
     *
     * @param Date $d The webtrees date.
     *
     * @return string The reconstructed GEDCOM string (empty when the date is blank).
     */
    private static function rawValue(Date $d): string
    {
        $value = '';

        if ($d->qual1 !== '') {
            $value .= $d->qual1 . ' ';
        }

        $value .= self::gedcomDate($d->minimumDate());

        if ($d->qual2 !== '') {
            $value .= ' ' . $d->qual2 . ' ' . self::gedcomDate($d->maximumDate());
        }

        return self::collapseWhitespace($value);
    }

    /**
     * Returns the calendar-neutral GEDCOM day/month/year of a calendar date.
     *
     * The leading calendar escape (e.g. `@#DGREGORIAN@`) is dropped: it is noise
     * for the precision rules and never part of a Gregorian source value.
     *
     * @param AbstractCalendarDate $date The calendar date to format.
     *
     * @return string The reconstructed `[day] [month] [year]` GEDCOM fragment.
     */
    private static function gedcomDate(AbstractCalendarDate $date): string
    {
        return self::collapseWhitespace($date->format('%A %O %E'));
    }

    /**
     * Collapses runs of whitespace into single spaces and trims the result.
     *
     * @param string $value The string to normalise.
     *
     * @return string The whitespace-normalised string.
     */
    private static function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Converts a webtrees calendar date into an engine {@see DateValue}, turning
     * the importer's `0` sentinels for an absent month or day into `null`.
     *
     * @param AbstractCalendarDate $date An interpretable calendar date (year set).
     *
     * @return DateValue The equivalent engine value.
     */
    private static function toDateValue(AbstractCalendarDate $date): DateValue
    {
        $month = $date->month() !== 0 ? $date->month() : null;
        $day   = $date->day() !== 0 ? $date->day() : null;

        return new DateValue($date->year(), $month, $day);
    }

    /**
     * Picks the precision from the leading qualifier token and the bounds.
     *
     * @param string               $raw     The reconstructed GEDCOM value.
     * @param AbstractCalendarDate $minimum The earliest calendar date.
     * @param AbstractCalendarDate $maximum The latest calendar date.
     *
     * @return DatePrecision The precision the range should carry.
     */
    private static function precision(
        string $raw,
        AbstractCalendarDate $minimum,
        AbstractCalendarDate $maximum,
    ): DatePrecision {
        $up = strtoupper(ltrim($raw));

        if (
            str_starts_with($up, 'ABT ')
            || str_starts_with($up, 'EST ')
            || str_starts_with($up, 'CAL ')
        ) {
            return DatePrecision::Approximate;
        }

        if (
            str_starts_with($up, 'BET ')
            || str_starts_with($up, 'FROM ')
        ) {
            return DatePrecision::Interval;
        }

        if ($minimum->year() !== $maximum->year()) {
            return DatePrecision::Interval;
        }

        if ($minimum->month() !== $maximum->month()) {
            return DatePrecision::Month;
        }

        if (
            ($minimum->day() !== 0)
            && ($minimum->day() === $maximum->day())
        ) {
            return DatePrecision::Exact;
        }

        if ($minimum->month() !== 0) {
            return DatePrecision::Month;
        }

        return DatePrecision::Year;
    }
}
