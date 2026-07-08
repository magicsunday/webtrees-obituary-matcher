<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\SearchSignature;

use function array_map;
use function array_unique;
use function array_values;
use function hash;
use function json_encode;
use function sort;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Derives a {@see SearchSignature} from a candidate's intrinsic, search-defining state — the
 * normalised name, birth year and places/region the finder is asked to search on (§5.2d). It reads
 * ONLY the person's own searchable data, never policy (excluded hosts) or request-building details, so
 * the matcher derives the identical signature at enqueue time and again when a drained result comes
 * back — the negative memory keyed by it lines up across both moments and across finders. Name and
 * place tokens run through {@see Normalizer::strip()} so a diacritic/spelling variant (Müller/Mueller)
 * collapses to one signature; places are sorted so their order in the tree does not change the result.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class SearchSignatureFactory
{
    /**
     * Private constructor: this is a static-only derivation helper.
     */
    private function __construct()
    {
    }

    /**
     * Derives the search signature for the given candidate.
     *
     * @param PersonCandidate $candidate The candidate whose search-defining state is fingerprinted.
     *
     * @return SearchSignature The stable signature of that state.
     */
    public static function fromCandidate(PersonCandidate $candidate): SearchSignature
    {
        $given   = array_map(Normalizer::strip(...), $candidate->name->givenNames);
        $surname = Normalizer::strip($candidate->name->surname);

        $birthSurname = ($candidate->name->birthSurname !== null)
            ? Normalizer::strip($candidate->name->birthSurname)
            : '';

        $places = array_map(
            static fn (Place $place): string => Normalizer::strip($place->name),
            $candidate->places,
        );

        // Sort + unique the places so the order the tree happens to list them in does not change the
        // signature (the search is over the SET of places, not a sequence).
        $places = array_values(array_unique($places));
        sort($places);

        $region = ($candidate->birthPlace?->region !== null)
            ? Normalizer::strip($candidate->birthPlace->region)
            : '';

        // JSON_INVALID_UTF8_SUBSTITUTE: the name/place tokens originate from GEDCOM, which a legacy
        // (ANSEL/Latin-1) import can leave with invalid UTF-8 byte sequences that Normalizer::strip does
        // not scrub. Since the encoded value is ONLY hashed (never displayed), substituting an invalid
        // byte keeps the signature deterministically computable rather than throwing and failing the
        // whole enqueue/drain for that person.
        $material = json_encode(
            [
                'given'        => $given,
                'surname'      => $surname,
                'birthSurname' => $birthSurname,
                'birthYear'    => $candidate->birth->earliest?->year,
                'places'       => $places,
                'region'       => $region,
            ],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return new SearchSignature(hash('sha256', $material));
    }
}
