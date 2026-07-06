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
 * Scoring weights and thresholds for the matching engines: the frozen Phase-1 `listLevel()` profile
 * and the `enriched()` profile.
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
     * @param int $maxPlausibleAge Highest age at death (in years) that is treated as plausible;
     *                             above it the age is flagged as a conflict instead of rewarded.
     * @param int $maxRelatives    Maximum points for the relatives signal (0 = off, list level).
     * @param int $maxAge          Maximum points for the age signal (0 = off, list level).
     * @param int $maxCemetery     Maximum points for the cemetery signal (0 = off, list level).
     */
    public function __construct(
        public int $maxName = 45,
        public int $maxBirth = 30,
        public int $maxPlace = 15,
        public int $maxPlausibility = 10,
        public int $maxPenalty = 50,
        public int $ambiguityGap = 10,
        public int $minPlausibleAge = 10,
        public int $maxPlausibleAge = 120,
        public int $maxRelatives = 0,
        public int $maxAge = 0,
        public int $maxCemetery = 0,
    ) {
    }

    /**
     * The frozen Phase-1 list-level profile (identical to the default constructor): the enrichment
     * caps are 0 so the base engine never awards enriched points.
     *
     * @return self
     */
    public static function listLevel(): self
    {
        return new self();
    }

    /**
     * The conservative, provisional enriched-detail profile: the base caps are rebalanced to make
     * room for the relatives/age/cemetery signals. These weights are NOT tuned against real data
     * yet — they hold until finder-detail produces detail-page enrichment to evaluate against.
     *
     * @return self
     */
    public static function enriched(): self
    {
        return new self(
            maxName: 35,
            maxBirth: 25,
            maxPlace: 10,
            maxPlausibility: 10,
            maxPenalty: 50,
            ambiguityGap: 10,
            minPlausibleAge: 10,
            maxPlausibleAge: 120,
            maxRelatives: 35,
            maxAge: 20,
            maxCemetery: 10,
        );
    }

    /**
     * The enriched profile with the six admin-editable base caps overridden. Every non-editable
     * enriched value — the plausibility window and the relatives/age/cemetery caps — is carried over
     * from {@see self::enriched()} unchanged, so an operator retunes the base weights without ever
     * disturbing the enrichment caps. Passing the enriched profile's own base caps reproduces it
     * verbatim, which is what keeps the editable-weight DEFAULTS from changing live scoring.
     *
     * @param int $maxName         Maximum points for a name signal.
     * @param int $maxBirth        Maximum points for a birth-date signal.
     * @param int $maxPlace        Maximum points for a place signal.
     * @param int $maxPlausibility Maximum points for a plausibility signal.
     * @param int $maxPenalty      Maximum conflict penalty that can be applied.
     * @param int $ambiguityGap    Score gap below which a match is considered ambiguous.
     *
     * @return self The enriched profile carrying the overridden base caps.
     */
    public static function enrichedWith(
        int $maxName,
        int $maxBirth,
        int $maxPlace,
        int $maxPlausibility,
        int $maxPenalty,
        int $ambiguityGap,
    ): self {
        $enriched = self::enriched();

        return new self(
            maxName: $maxName,
            maxBirth: $maxBirth,
            maxPlace: $maxPlace,
            maxPlausibility: $maxPlausibility,
            maxPenalty: $maxPenalty,
            ambiguityGap: $ambiguityGap,
            minPlausibleAge: $enriched->minPlausibleAge,
            maxPlausibleAge: $enriched->maxPlausibleAge,
            maxRelatives: $enriched->maxRelatives,
            maxAge: $enriched->maxAge,
            maxCemetery: $enriched->maxCemetery,
        );
    }
}
