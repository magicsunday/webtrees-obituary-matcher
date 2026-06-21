<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;

use function min;

/**
 * Scores a stated age ("im Alter von N") against the candidate's birth. The death year minus the
 * age yields a two-year implied birth window (no birthday → the person could have turned N in
 * either of two calendar years); the candidate's birth year range is compared to it. Positive-only:
 * a large mismatch is NOT a conflict in 2b — the age extraction is unproven.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class AgeScorer
{
    /**
     * Points awarded when the candidate birth range overlaps the implied window.
     */
    private const int AGE_OVERLAP = 20;

    /**
     * Points awarded for a near miss (a 1- or 2-year gap to the implied window).
     */
    private const int AGE_NEAR = 10;

    /**
     * The largest year gap still treated as a near miss.
     */
    private const int NEAR_GAP = 2;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores the stated age against the candidate's birth.
     *
     * @param DateRange $candidateBirth The candidate's birth range.
     * @param int|null  $age            The age stated in the notice, or null when absent.
     * @param DateRange $death          The death range from the notice.
     *
     * @return SignalScore The age signal (0..maxAge).
     */
    public function score(DateRange $candidateBirth, ?int $age, DateRange $death): SignalScore
    {
        $max = $this->config->maxAge;

        if (
            ($age === null)
            || !$candidateBirth->isKnown()
            || !$death->isKnown()
            || !$candidateBirth->earliest instanceof DateValue
            || !$candidateBirth->latest instanceof DateValue
            || !$death->earliest instanceof DateValue
        ) {
            return new SignalScore(0, $max, []);
        }

        // Uses the death range's earliest year as the reference; a wide death range collapses to its
        // lower bound here, which is acceptable for this positive-only signal.
        $deathYear = $death->earliest->year;
        $impliedLo = $deathYear - $age - 1;
        $impliedHi = $deathYear - $age;
        $birthLo   = $candidateBirth->earliest->year;
        $birthHi   = $candidateBirth->latest->year;

        if (
            ($birthLo <= $impliedHi)
            && ($impliedLo <= $birthHi)
        ) {
            return new SignalScore(min(self::AGE_OVERLAP, $max), $max, ['age matches the implied birth window']);
        }

        $gap = ($birthLo > $impliedHi) ? ($birthLo - $impliedHi) : ($impliedLo - $birthHi);

        if ($gap <= self::NEAR_GAP) {
            return new SignalScore(min(self::AGE_NEAR, $max), $max, ['age near the implied birth window']);
        }

        return new SignalScore(0, $max, []);
    }
}
