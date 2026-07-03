<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function array_intersect;
use function array_unique;
use function array_values;
use function count;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * A DISPLAY-ONLY loose name match used to highlight a notice relative against a tree family member in
 * the review screen's family-graph panel. It compares two plain display names by normalised
 * whitespace-separated tokens: they match when the token sets share at least two tokens (typically a
 * given name plus the surname), or when both collapse to the same single token (a mononym / an
 * incomplete record).
 *
 * This is a presentation heuristic, NOT the authoritative relatives score: the precise surname-plus-
 * given-name rule lives in {@see \MagicSunday\ObituaryMatcher\Scoring\RelativeScorer}, which parses a
 * {@see \MagicSunday\ObituaryMatcher\Domain\PersonName} and belongs to the Scoring layer the webtrees-
 * free Ui may not reach. The panel therefore uses this looser, order-independent token overlap purely
 * to mark which names visibly correspond; the "why this score" breakdown carries the scored signal.
 *
 * Because it cannot identify the surname token without the Parsing layer, the ≥2-shared-tokens rule
 * can over-match two people who share two given names but have different surnames (e.g. "Anna Maria
 * Schmidt" ↔ "Anna Maria Müller"). That deliberate false-positive is acceptable for a display hint —
 * the authoritative relatives match is the scored signal, not this badge — and it also lets a
 * maiden-vs-married surname change still highlight the same person.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class FamilyNameMatch
{
    /**
     * The minimum number of shared normalised tokens for a multi-token loose match.
     */
    private const int MIN_SHARED_TOKENS = 2;

    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Returns whether the given name loosely matches ANY of the candidate names — the "is this side's
     * name present on the other side" predicate the family-graph panel needs on both directions.
     *
     * @param string       $name       The plain display name to test.
     * @param list<string> $candidates The plain display names to test against.
     *
     * @return bool Whether any candidate loosely matches the name.
     */
    public static function matchesAny(string $name, array $candidates): bool
    {
        // Tokenise the probe name ONCE, then compare its token set against each candidate's — so a
        // matchesAny over N candidates tokenises $name a single time instead of once per candidate.
        $nameTokens = self::tokens($name);

        foreach ($candidates as $candidate) {
            if (self::tokensMatch($nameTokens, self::tokens($candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether the tree member's name and the notice relative's name loosely correspond.
     *
     * @param string $treeName   The tree family member's plain display name.
     * @param string $noticeName The notice relative's plain name.
     *
     * @return bool Whether the two names loosely match.
     */
    public static function matches(string $treeName, string $noticeName): bool
    {
        return self::tokensMatch(self::tokens($treeName), self::tokens($noticeName));
    }

    /**
     * Returns whether two already-tokenised names loosely correspond: they share at least two tokens
     * (typically a given name plus the surname), or both collapse to the same single token.
     *
     * @param list<string> $treeTokens   The first name's distinct normalised tokens.
     * @param list<string> $noticeTokens The second name's distinct normalised tokens.
     *
     * @return bool Whether the two token sets loosely match.
     */
    private static function tokensMatch(array $treeTokens, array $noticeTokens): bool
    {
        if (
            ($treeTokens === [])
            || ($noticeTokens === [])
        ) {
            return false;
        }

        $shared = array_intersect($treeTokens, $noticeTokens);

        if (count($shared) >= self::MIN_SHARED_TOKENS) {
            return true;
        }

        // A single-token name (a mononym or an incomplete record) matches only its exact equal, so a
        // shared surname alone never marks a multi-part name as corresponding.
        return (count($treeTokens) === 1)
            && (count($noticeTokens) === 1)
            && ($treeTokens === $noticeTokens);
    }

    /**
     * Splits a name into its distinct normalised tokens (case / diacritic / digraph folded, titles and
     * affixes stripped, whitespace collapsed).
     *
     * @param string $name The plain display name.
     *
     * @return list<string> The distinct normalised tokens.
     */
    private static function tokens(string $name): array
    {
        $tokens = preg_split('/\s+/', Normalizer::strip($name), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_values(array_unique($tokens));
    }
}
