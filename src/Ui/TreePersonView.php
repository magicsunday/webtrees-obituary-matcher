<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

/**
 * A webtrees-free, pre-formatted projection of the tree person under review. The handler builds it
 * from the live {@see \Fisharebest\Webtrees\Individual} so the review view model never reads the
 * (potentially stale) stored payload for the tree side. Dates arrive already formatted for display.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class TreePersonView
{
    /**
     * Constructor.
     *
     * @param string      $xref       The individual's XREF.
     * @param string      $name       The individual's primary display name.
     * @param string|null $birthDate  The formatted birth date, or null when absent.
     * @param string|null $birthPlace The birth place, or null when absent.
     * @param string|null $deathDate  The formatted death date, or null when absent.
     * @param string      $sex        The sex code (M / F / U / X).
     */
    public function __construct(
        public string $xref,
        public string $name,
        public ?string $birthDate,
        public ?string $birthPlace,
        public ?string $deathDate,
        public string $sex,
    ) {
    }
}
