<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * Scoring weights and thresholds for the Phase-1 matching engine.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ScoreConfig
{
    /**
     * Constructor.
     *
     * @param int $maxName         Maximum points for a name signal.
     * @param int $maxBirth        Maximum points for a birth-date signal.
     * @param int $maxPlace        Maximum points for a place signal.
     * @param int $maxPlausibility Maximum points for a plausibility signal.
     * @param int $maxPenalty      Maximum conflict penalty that can be applied.
     * @param int $ambiguityGap    Score gap below which a match is considered ambiguous.
     * @param int $minPlausibleAge Lowest age at death (in years) that is treated as plausible;
     *                             below it the age is flagged as a conflict instead of rewarded.
     */
    public function __construct(
        public int $maxName = 45,
        public int $maxBirth = 30,
        public int $maxPlace = 15,
        public int $maxPlausibility = 10,
        public int $maxPenalty = 50,
        public int $ambiguityGap = 10,
        public int $minPlausibleAge = 10,
    ) {
    }
}
