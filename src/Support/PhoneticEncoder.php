<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

/**
 * Encodes a word into a phonetic key for fuzzy surname comparison.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface PhoneticEncoder
{
    /**
     * Encodes the given word into a phonetic key.
     *
     * @param string $value The word to encode.
     *
     * @return string The phonetic key (may be empty for empty input).
     */
    public function encode(string $value): string;
}
