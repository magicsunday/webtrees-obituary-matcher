<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Support\Normalizer;

use function min;

/**
 * Scores place agreement using string matching and light hierarchy. Returns >= 0.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PlaceScorer
{
    /**
     * Points awarded when the notice place matches a residence.
     */
    private const int RESIDENCE = 12;

    /**
     * Points awarded when the notice place matches a residence region.
     */
    private const int WITHIN_REGION = 8;

    /**
     * Points awarded when the notice place matches the birth place.
     */
    private const int BIRTH_PLACE = 5;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores place agreement between a tree candidate and an obituary notice place.
     *
     * @param PersonCandidate $candidate   The candidate.
     * @param Place|null      $noticePlace The place mentioned in the notice.
     *
     * @return SignalScore The place signal (0..maxPlace).
     */
    public function score(PersonCandidate $candidate, ?Place $noticePlace): SignalScore
    {
        if (!$noticePlace instanceof Place) {
            return new SignalScore(0, $this->config->maxPlace, []);
        }

        $needle = Normalizer::normalize($noticePlace->name);

        // An empty needle must never match: '' === '' would otherwise award place points for nothing.
        // This also covers the region branch, where an empty $needle is now impossible.
        if ($needle === '') {
            return new SignalScore(0, $this->config->maxPlace, []);
        }

        foreach ($candidate->places as $residence) {
            if (Normalizer::normalize($residence->name) === $needle) {
                return new SignalScore(min(self::RESIDENCE, $this->config->maxPlace), $this->config->maxPlace, ['place matches residence']);
            }
        }

        foreach ($candidate->places as $residence) {
            if (
                ($residence->region !== null)
                && (Normalizer::normalize($residence->region) === $needle)
            ) {
                return new SignalScore(min(self::WITHIN_REGION, $this->config->maxPlace), $this->config->maxPlace, ['place within region']);
            }
        }

        if (
            ($candidate->birthPlace instanceof Place)
            && (Normalizer::normalize($candidate->birthPlace->name) === $needle)
        ) {
            return new SignalScore(min(self::BIRTH_PLACE, $this->config->maxPlace), $this->config->maxPlace, ['birth place matches']);
        }

        return new SignalScore(0, $this->config->maxPlace, []);
    }
}
