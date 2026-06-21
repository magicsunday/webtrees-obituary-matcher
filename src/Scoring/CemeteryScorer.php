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

use function in_array;
use function mb_strlen;
use function min;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Scores the burial cemetery as its own review-visible signal (separate from PlaceScorer, because a
 * cemetery is a burial location, not a residence/birth/death place). The notice cemetery is a
 * free-text Place name like "Waldfriedhof Musterstadt", so the candidate's place is matched as a
 * whole token within the cemetery text (and must be at least 4 characters, to avoid short-substring
 * noise such as "Au" inside "Auenwald"). Positive-only, deliberately weighted low.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CemeteryScorer
{
    /**
     * Points awarded when the cemetery names a known residence town.
     */
    private const int CEMETERY_PLACE = 10;

    /**
     * Points awarded when the cemetery names a known residence region.
     */
    private const int CEMETERY_REGION = 6;

    /**
     * The minimum token length that may match, to suppress short-substring noise.
     */
    private const int MIN_TOKEN_LENGTH = 4;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores the burial cemetery against the candidate's known places.
     *
     * @param PersonCandidate $candidate The tree candidate.
     * @param Place|null      $cemetery  The cemetery named in the notice.
     *
     * @return SignalScore The cemetery signal (0..maxCemetery).
     */
    public function score(PersonCandidate $candidate, ?Place $cemetery): SignalScore
    {
        $max = $this->config->maxCemetery;

        if (!$cemetery instanceof Place) {
            return new SignalScore(0, $max, []);
        }

        $tokens = $this->tokens($cemetery->name);

        if ($tokens === []) {
            return new SignalScore(0, $max, []);
        }

        foreach ($candidate->places as $residence) {
            if ($this->isWholeTokenMatch($residence->name, $tokens)) {
                return new SignalScore(min(self::CEMETERY_PLACE, $max), $max, ['cemetery names a known place']);
            }
        }

        foreach ($candidate->places as $residence) {
            if (
                ($residence->region !== null)
                && $this->isWholeTokenMatch($residence->region, $tokens)
            ) {
                return new SignalScore(min(self::CEMETERY_REGION, $max), $max, ['cemetery within a known region']);
            }
        }

        return new SignalScore(0, $max, []);
    }

    /**
     * Returns whether the normalised value (≥ 4 chars) is one of the whole tokens.
     *
     * @param string       $value  The candidate place name or region.
     * @param list<string> $tokens The normalised cemetery tokens.
     *
     * @return bool
     */
    private function isWholeTokenMatch(string $value, array $tokens): bool
    {
        $needle = Normalizer::normalize($value);

        if (mb_strlen($needle, 'UTF-8') < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        return in_array($needle, $tokens, true);
    }

    /**
     * Splits the normalised cemetery name into whole tokens.
     *
     * @param string $value The cemetery free-text name.
     *
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $normalized = Normalizer::normalize($value);

        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return ($tokens === false) ? [] : $tokens;
    }
}
