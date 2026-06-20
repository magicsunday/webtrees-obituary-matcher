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
 * A place with optional light hierarchy supplied by the caller. No I/O.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class Place
{
    /**
     * Constructor.
     *
     * @param string       $name       Raw place name.
     * @param string|null  $normalized Pre-normalised form, if the caller has one.
     * @param string|null  $region     Containing region, if known.
     * @param string|null  $country    Country, if known.
     * @param list<string> $aliases    Alternative names for the same place.
     */
    public function __construct(
        public string $name,
        public ?string $normalized = null,
        public ?string $region = null,
        public ?string $country = null,
        public array $aliases = [],
    ) {
    }
}
