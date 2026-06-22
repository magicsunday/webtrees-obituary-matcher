<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;

use function hash;

/**
 * Derives the canonical review-route row key from an obituary URL: the SHA-256 of the
 * identity-normalised URL. This is the public half of {@see FileMatchStore}'s file-name key (the
 * store additionally folds in the candidate identifier) and the value the review route carries, so
 * the individual-tab link and the store agree on one single definition of a row's identity.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class StoredMatchKey
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Returns the canonical row key for the given obituary URL.
     *
     * @param string $url The source notice URL (raw, pre-normalisation).
     *
     * @return string The SHA-256 row key over the identity-normalised URL.
     */
    public static function fromUrl(string $url): string
    {
        return hash('sha256', UrlNormalizer::normalizeForIdentity($url));
    }
}
