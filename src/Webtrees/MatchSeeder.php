<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;

/**
 * Seeds a tree-scoped match store with a single synthetic suggestion so a developer can see the
 * individual tab on a real instance without running the producer, feeder or scoring engine. The
 * payload is fabricated, never derived from real obituary data: the source URL points at a reserved
 * `.example`/`.test` domain and the only fact written is the death date passed in by the caller, so
 * no third-party personal data ever reaches the store (DSGVO). The class is a static-only utility: it
 * holds no state and is never instantiated.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class MatchSeeder
{
    /**
     * The synthetic score assigned to each classification band. The values are illustrative only and
     * do not come from the scoring engine; they merely place the seeded row in a plausible band so the
     * tab renders a realistic-looking suggestion.
     *
     * @var array<string, int>
     */
    private const array BAND_SCORES = [
        'strong'   => 92,
        'probable' => 78,
        'possible' => 61,
        'weak'     => 48,
        'none'     => 20,
    ];

    /**
     * The synthetic score used when the requested band is not one of the known bands.
     */
    private const int DEFAULT_SCORE = 50;

    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Builds a synthetic suggestion for the given candidate and stores it as a pending row. The score
     * is derived from the band, the source URL is fabricated on a reserved `.example` domain and the
     * only extracted fact is the optional death date, so the seeded row never carries real obituary
     * data.
     *
     * @param MatchStore  $store     The tree-scoped store the suggestion is written to.
     * @param string      $xref      The candidate identifier (webtrees XREF) the suggestion belongs to.
     * @param MatchStatus $status    The lifecycle status the seeded row is written with.
     * @param string      $band      The classification band; also used verbatim as the classification.
     * @param string|null $deathDate The synthetic death date (`YYYY-MM-DD`), or null for no facts.
     *
     * @return StoredMatch The stored suggestion that was written.
     */
    public static function seed(
        MatchStore $store,
        string $xref,
        MatchStatus $status,
        string $band,
        ?string $deathDate,
    ): StoredMatch {
        $obituaryUrl = 'https://trauer.example/' . $xref;

        // Build the payload from the canonical zero-value shape and override only the keys that
        // differ, mirroring how ClassifiedMatch::toArray() overrides on top of its base shape. This
        // keeps the ClassifiedMatchArray literal defined in exactly one place (ClassifiedMatch).
        $match                   = ClassifiedMatch::emptyArray($xref, $obituaryUrl);
        $match['score']          = self::BAND_SCORES[$band] ?? self::DEFAULT_SCORE;
        $match['classification'] = $band;
        $match['extractedFacts'] = $deathDate === null ? [] : ['deathDate' => $deathDate];

        $storedMatch = new StoredMatch($xref, $obituaryUrl, $status, $match);

        $store->upsertPending($storedMatch);

        return $storedMatch;
    }
}
