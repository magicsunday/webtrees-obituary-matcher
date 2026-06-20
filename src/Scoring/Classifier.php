<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;

use function sprintf;

/**
 * Turns a scored result set into a confidence band and an ambiguity flag, applying
 * the hard-conflict cap and the runner-up ambiguity rule.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class Classifier
{
    /**
     * Lowest total for the Strong band.
     */
    private const int THRESHOLD_STRONG = 85;

    /**
     * Lowest total for the Probable band.
     */
    private const int THRESHOLD_PROBABLE = 70;

    /**
     * Lowest total for the Possible band.
     */
    private const int THRESHOLD_POSSIBLE = 55;

    /**
     * Lowest total for the Weak band.
     */
    private const int THRESHOLD_WEAK = 40;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration, defaulting to the standard thresholds.
     */
    public function __construct(private ScoreConfig $config = new ScoreConfig())
    {
    }

    /**
     * Classifies the best result against the full result set for the same query.
     *
     * @param MatchExplanation       $best       The highest-scoring result.
     * @param list<MatchExplanation> $allResults All results for the same query/person set.
     *
     * @return Classification The band (after the hard-conflict cap) and the ambiguity flag.
     */
    public function classify(MatchExplanation $best, array $allResults): Classification
    {
        $reasons   = [];
        $band      = $this->band($best->total);
        $reasons[] = sprintf('score %d', $best->total);

        if (
            $best->conflicts->hasHardConflict()
            && $this->stronger($band, Band::Possible)
        ) {
            $band      = Band::Possible;
            $reasons[] = 'capped to possible by a hard conflict';
        }

        $ambiguous = false;

        // When the best match itself is clean, a band-capped runner-up that carries a hard
        // conflict is a known-different person and must not make the clean best look uncertain;
        // it is therefore excluded from the runner-up consideration.
        $excludeConflicted = !$best->conflicts->hasHardConflict();
        $second            = $this->secondBest($best, $allResults, $excludeConflicted);

        // Ambiguity applies whenever the best is at least a possible match AND the runner-up is
        // within the gap: a possible-band pair (e.g. 60 vs 58) genuinely fits two people equally,
        // while a weak/none best stays unflagged so low-confidence noise is not surfaced.
        if (
            ($best->total >= self::THRESHOLD_POSSIBLE)
            && ($second !== null)
            && (($best->total - $second) < $this->config->ambiguityGap)
        ) {
            $ambiguous = true;
            $reasons[] = sprintf('runner-up within %d points', $this->config->ambiguityGap);
        }

        return new Classification($band, $ambiguous, $reasons);
    }

    /**
     * Maps a total score to its confidence band.
     *
     * @param int $score The total score.
     *
     * @return Band The band for that score.
     */
    private function band(int $score): Band
    {
        return match (true) {
            $score >= self::THRESHOLD_STRONG   => Band::Strong,
            $score >= self::THRESHOLD_PROBABLE => Band::Probable,
            $score >= self::THRESHOLD_POSSIBLE => Band::Possible,
            $score >= self::THRESHOLD_WEAK     => Band::Weak,
            default                            => Band::None,
        };
    }

    /**
     * Checks whether one band ranks strictly above a threshold band.
     *
     * @param Band $band      The candidate band.
     * @param Band $threshold The threshold band.
     *
     * @return bool Whether $band ranks strictly above $threshold.
     */
    private function stronger(Band $band, Band $threshold): bool
    {
        return $this->rank($band) > $this->rank($threshold);
    }

    /**
     * Returns a numeric rank for a band (higher = stronger).
     *
     * @param Band $band The band.
     *
     * @return int A numeric rank (higher = stronger).
     */
    private function rank(Band $band): int
    {
        return match ($band) {
            Band::Strong   => 4,
            Band::Probable => 3,
            Band::Possible => 2,
            Band::Weak     => 1,
            Band::None     => 0,
        };
    }

    /**
     * Finds the next-highest total among the other results.
     *
     * @param MatchExplanation       $best              The best result (excluded by identity).
     * @param list<MatchExplanation> $allResults        All results.
     * @param bool                   $excludeConflicted Whether to skip results carrying a hard conflict.
     *
     * @return int|null The next-highest total among the others, or null when there is none.
     */
    private function secondBest(MatchExplanation $best, array $allResults, bool $excludeConflicted): ?int
    {
        $second = null;

        foreach ($allResults as $result) {
            if ($result === $best) {
                continue;
            }

            if (
                $excludeConflicted
                && $result->conflicts->hasHardConflict()
            ) {
                continue;
            }

            if (
                ($second === null)
                || ($result->total > $second)
            ) {
                $second = $result->total;
            }
        }

        return $second;
    }
}
