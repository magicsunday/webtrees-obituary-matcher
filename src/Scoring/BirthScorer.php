<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;

use function in_array;
use function min;

/**
 * Scores birth-date agreement, interval-aware. Returns >= 0 only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class BirthScorer
{
    /**
     * Points awarded when both birth dates are exact and equal.
     */
    private const int EXACT_EQUAL = 30;

    /**
     * Points awarded when one exact date falls within the other range.
     */
    private const int WITHIN_RANGE = 25;

    /**
     * Points awarded when both dates share the same month and year.
     */
    private const int SAME_MONTH_YEAR = 22;

    /**
     * Points awarded when both dates share the same year.
     */
    private const int SAME_YEAR = 16;

    /**
     * Points awarded when the two ranges merely overlap.
     */
    private const int LIFESPAN_OVERLAP = 5;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores birth-date agreement between a tree candidate and an obituary notice.
     *
     * @param DateRange $candidate The candidate's birth range.
     * @param DateRange $notice    The notice's birth range.
     *
     * @return SignalScore The birth signal (0..maxBirth).
     */
    public function score(DateRange $candidate, DateRange $notice): SignalScore
    {
        if (
            !$candidate->isKnown()
            || !$notice->isKnown()
        ) {
            return new SignalScore(0, $this->config->maxBirth, []);
        }

        if (
            $candidate->isExact()
            && $notice->isExact()
            && ($candidate->earliest instanceof DateValue)
            && ($notice->earliest instanceof DateValue)
        ) {
            return $candidate->earliest->comparable() === $notice->earliest->comparable()
                ? new SignalScore(min(self::EXACT_EQUAL, $this->config->maxBirth), $this->config->maxBirth, ['exact birth date'])
                : new SignalScore(0, $this->config->maxBirth, []);
        }

        if (
            $notice->isExact()
            && ($notice->earliest instanceof DateValue)
            && $candidate->contains($notice->earliest)
        ) {
            return new SignalScore(min(self::WITHIN_RANGE, $this->config->maxBirth), $this->config->maxBirth, ['birth within range']);
        }

        if (
            $candidate->isExact()
            && ($candidate->earliest instanceof DateValue)
            && $notice->contains($candidate->earliest)
        ) {
            return new SignalScore(min(self::WITHIN_RANGE, $this->config->maxBirth), $this->config->maxBirth, ['birth within range']);
        }

        if ($this->sameMonthYear($candidate, $notice)) {
            return new SignalScore(min(self::SAME_MONTH_YEAR, $this->config->maxBirth), $this->config->maxBirth, ['same month and year']);
        }

        if (
            ($candidate->earliest instanceof DateValue)
            && ($notice->earliest instanceof DateValue)
            && ($candidate->earliest->year === $notice->earliest->year)
        ) {
            return new SignalScore(min(self::SAME_YEAR, $this->config->maxBirth), $this->config->maxBirth, ['same year']);
        }

        if ($candidate->overlaps($notice)) {
            return new SignalScore(min(self::LIFESPAN_OVERLAP, $this->config->maxBirth), $this->config->maxBirth, ['lifespan overlap']);
        }

        return new SignalScore(0, $this->config->maxBirth, []);
    }

    /**
     * Checks whether both ranges share the same month and year with month-level precision.
     *
     * @param DateRange $a First range (known).
     * @param DateRange $b Second range (known).
     *
     * @return bool Whether both pin the same month and year at sub-year precision.
     */
    private function sameMonthYear(DateRange $a, DateRange $b): bool
    {
        $monthPrecisions = [DatePrecision::Exact, DatePrecision::Month];

        return ($a->earliest instanceof DateValue)
            && ($b->earliest instanceof DateValue)
            && ($a->earliest->month !== null)
            && ($b->earliest->month !== null)
            && ($a->earliest->year === $b->earliest->year)
            && ($a->earliest->month === $b->earliest->month)
            && in_array($a->precision, $monthPrecisions, true)
            && in_array($b->precision, $monthPrecisions, true);
    }
}
