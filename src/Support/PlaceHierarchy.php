<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function trim;

/**
 * Splits a webtrees gedcomName() place into its comma-separated hierarchy segments.
 *
 * A GEDCOM place name is a comma-separated hierarchy, most-specific first, of VARIABLE depth
 * ("Güldengossa", "Bad Hersfeld, Hessen, Deutschland", "Connewitz, Leipzig, Sachsen, Deutschland").
 * An obituary notice place is usually just the town, so a scorer must match the notice place against
 * every segment of the candidate place rather than the whole verbatim string. This helper isolates
 * that split; each caller normalises the raw segments in its own way.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class PlaceHierarchy
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Splits a place on commas, trims each part and drops empty parts, returning the RAW segments.
     *
     * The segments keep their original casing and diacritics so each caller may normalise them in
     * its own way. A single-segment place returns a one-element list; an empty or comma-only string
     * returns an empty list.
     *
     * @param string $place The raw place string (a comma-separated GEDCOM hierarchy).
     *
     * @return list<string> The trimmed, non-empty comma-segments, most-specific first.
     */
    public static function segments(string $place): array
    {
        $trimmed = array_map(trim(...), explode(',', $place));

        return array_values(array_filter($trimmed, static fn (string $segment): bool => $segment !== ''));
    }
}
