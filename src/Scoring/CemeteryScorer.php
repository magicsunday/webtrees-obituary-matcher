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
use MagicSunday\ObituaryMatcher\Support\PlaceHierarchy;

use function in_array;
use function mb_strlen;
use function min;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Scores the burial cemetery as its own review-visible signal (separate from PlaceScorer, because a
 * cemetery is a burial location, not a residence/birth/death place). The notice cemetery is a
 * free-text Place name like "Waldfriedhof Musterstadt", so the candidate's place is matched against
 * the cemetery text per word: every word of the (≥ 4-character) candidate segment must appear as a
 * whole cemetery token. The ≥ 4-character guard applies to the whole normalised segment value, to
 * avoid short-substring noise such as "Au" inside "Auenwald". Both sides are tokenised on runs of
 * non-alphanumeric characters, so attached punctuation (commas/periods/parentheses/slashes/hyphens)
 * in the free-text becomes a token boundary instead of fusing onto a token. Positive-only,
 * deliberately weighted low.
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

        // A real adapter place name can be a comma-separated GEDCOM hierarchy ("Town, Region,
        // Country"), so each comma-segment is tested as its own whole token against the cemetery.
        // The per-segment >= 4-char guard in isWholeTokenMatch() still applies.
        foreach ($candidate->places as $residence) {
            foreach (PlaceHierarchy::segments($residence->name) as $segment) {
                if ($this->isWholeTokenMatch($segment, $tokens)) {
                    return new SignalScore(min(self::CEMETERY_PLACE, $max), $max, ['cemetery names a known place']);
                }
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
     * Returns whether the normalised value (≥ 4 chars) is phrase-matched by the cemetery tokens.
     *
     * A candidate place segment can itself be multi-word ("Bad Hersfeld"), so the normalised value
     * is split into its words and EVERY word must appear as a whole cemetery token. A single-word
     * value reduces to the original whole-token check. The ≥ 4-char guard applies to the whole
     * normalised value (as before), suppressing short-substring noise such as "Au". The split is on
     * runs of non-alphanumeric characters — the same boundary the cemetery tokens use — so a
     * candidate segment carrying punctuation is tokenised symmetrically.
     *
     * @param string       $value  The candidate place name segment or region.
     * @param list<string> $tokens The normalised cemetery tokens.
     *
     * @return bool Whether every word of the value is a whole cemetery token.
     */
    private function isWholeTokenMatch(string $value, array $tokens): bool
    {
        $needle = Normalizer::normalize($value);

        if (mb_strlen($needle, 'UTF-8') < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);

        if (($words === false) || ($words === [])) {
            return false;
        }

        foreach ($words as $word) {
            if (!in_array($word, $tokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Splits the normalised cemetery name into whole tokens on non-alphanumeric boundaries.
     *
     * Splitting on any run of non-alphanumeric characters (not only whitespace) makes the tokens
     * punctuation-robust: a comma/period/parenthesis/slash/hyphen in the free-text name becomes a
     * token boundary instead of fusing onto a token (so "Waldfriedhof Bad Hersfeld, Hessen" yields
     * "hersfeld", not "hersfeld,"). After normalisation the meaningful characters are ASCII letters
     * and digits, so this cleanly drops the punctuation while leaving real words intact.
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

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return ($tokens === false) ? [] : $tokens;
    }
}
