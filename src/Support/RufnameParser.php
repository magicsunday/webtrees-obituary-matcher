<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function preg_match;
use function trim;

/**
 * Pure GEDCOM helper that extracts the German call name (the _RUFNAME tag value) from a raw
 * GEDCOM record. When several _RUFNAME lines are present the first one wins; an absent tag
 * yields null.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RufnameParser
{
    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Returns the call name from the first _RUFNAME line of the GEDCOM record, or null when
     * no _RUFNAME tag is present.
     *
     * @param string $gedcom The raw GEDCOM record.
     *
     * @return string|null The trimmed call name, or null when no _RUFNAME tag is present.
     */
    public static function parse(string $gedcom): ?string
    {
        if (preg_match('/(?:^|\n)\d+ _RUFNAME\s+(.+?)\s*(?:\n|$)/', $gedcom, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
