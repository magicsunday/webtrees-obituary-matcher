<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;

/**
 * Transport-neutral description of a single portal a finder can search, defensively narrowed from one
 * untrusted entry of the {@see GET /capabilities} response body. Every field is validated against a
 * conservative shape so a corrupt, oversize or hand-crafted capabilities document degrades to a
 * dropped entry rather than poisoning the admin UI that consumes it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderPortal
{
    /**
     * Constructor.
     *
     * @param string       $id      The portal identifier matching `^[a-z0-9][a-z0-9_.-]{0,63}$`.
     * @param string|null  $name    The human-readable portal name (≤200 chars) or null when absent/invalid.
     * @param string|null  $country The ISO-3166 alpha-2 country code or null when absent/invalid.
     * @param list<string> $regions The narrowed list of region labels (each ≤200 chars), possibly empty.
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $country,
        public array $regions,
    ) {
    }

    /**
     * Narrows one untrusted capabilities portal entry into a value object, or returns null when the
     * entry is not an array or carries no valid identifier.
     *
     * @param mixed $raw The untrusted portal entry from the capabilities response body.
     *
     * @return self|null The narrowed portal, or null when the entry is unusable.
     */
    public static function tryFromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $id = $raw['id'] ?? null;

        if (
            !is_string($id)
            || (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $id) !== 1)
        ) {
            return null;
        }

        $name = $raw['name'] ?? null;

        if (
            !is_string($name)
            || (mb_strlen($name) > 200)
        ) {
            $name = null;
        }

        $country = $raw['country'] ?? null;

        if (
            !is_string($country)
            || (preg_match('/^[A-Z]{2}$/D', $country) !== 1)
        ) {
            $country = null;
        }

        $regions    = [];
        $rawRegions = $raw['regions'] ?? null;

        if (is_array($rawRegions)) {
            foreach ($rawRegions as $region) {
                if (
                    is_string($region)
                    && (mb_strlen($region) <= 200)
                ) {
                    $regions[] = $region;
                }
            }
        }

        return new self($id, $name, $country, $regions);
    }
}
