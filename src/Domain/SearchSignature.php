<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * A stable fingerprint of the search-defining state of a person — the name, birth year and
 * places/region the finder is actually asked to search on (§5.2d). Two searches with the same
 * signature look for the same thing; a change to the person's searchable data yields a different
 * signature, which invalidates any negative memory recorded against the old one (so an edited person
 * is searched again). It is intentionally independent of policy (excluded hosts) and of the locale or
 * request-building details, so the matcher computes the SAME signature at enqueue time and again when
 * a drained result comes back — the negative memory keyed by it therefore lines up across both moments
 * and across multiple finders.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class SearchSignature
{
    /**
     * Constructor.
     *
     * @param string $hash The opaque signature hash (a lowercase hex SHA-256 digest of the normalised
     *                     search-defining fields).
     */
    public function __construct(
        public string $hash,
    ) {
    }

    /**
     * Whether this signature denotes the same search as another.
     *
     * @param self $other The signature to compare against.
     *
     * @return bool True when both signatures fingerprint the same search-defining state.
     */
    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
