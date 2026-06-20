<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;

use function min;

/**
 * Scores whether the candidate is a plausible subject of the notice. Returns >= 0.
 * The notice death date is used only for a sanity check, never as positive identity.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PlausibilityScorer
{
    /**
     * Points awarded when the age at the notice's death is plausible.
     */
    private const int PLAUSIBLE_AGE = 5;

    /**
     * Points awarded when the tree holds no death date for the candidate.
     */
    private const int TREE_DEATH_UNKNOWN = 3;

    /**
     * Points awarded when the candidate's gender is known.
     */
    private const int GENDER_CONSISTENT = 2;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores the plausibility of the candidate being the subject of the notice.
     *
     * @param PersonCandidate $candidate The tree candidate.
     * @param ObituaryRecord  $notice    The obituary notice.
     *
     * @return SignalScore The plausibility signal (0..maxPlausibility).
     */
    public function score(PersonCandidate $candidate, ObituaryRecord $notice): SignalScore
    {
        $score   = 0;
        $reasons = [];

        if (!$candidate->death->isKnown()) {
            $score += self::TREE_DEATH_UNKNOWN;
            $reasons[] = 'no death date in tree';
        }

        if ($candidate->gender !== Gender::Unknown) {
            $score += self::GENDER_CONSISTENT;
            $reasons[] = 'gender consistent';
        }

        if (
            ($candidate->birth->earliest instanceof DateValue)
            && ($candidate->birth->latest instanceof DateValue)
            && ($notice->death->earliest instanceof DateValue)
        ) {
            // The lower bound uses the latest birth endpoint (conservative minimum age);
            // the upper bound uses the earliest birth endpoint (conservative maximum age).
            $minAge = $notice->death->earliest->year - $candidate->birth->latest->year;
            $maxAge = $notice->death->earliest->year - $candidate->birth->earliest->year;

            if (
                ($minAge >= $this->config->minPlausibleAge)
                && ($maxAge <= $this->config->maxPlausibleAge)
            ) {
                $score += self::PLAUSIBLE_AGE;
                $reasons[] = 'plausible age (' . $minAge . ')';
            }
        }

        return new SignalScore(min($score, $this->config->maxPlausibility), $this->config->maxPlausibility, $reasons);
    }
}
