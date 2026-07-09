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
 * The webtrees-free projection of ONE persisted additional finder (§5.2f increment 2) for the control
 * panel: its base URL, a token-is-set boolean (the token VALUE never reaches a view) and its active flag.
 * The template renders one editable list row per instance and escapes every sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class AdditionalFinderRowView
{
    /**
     * Constructor.
     *
     * @param string $baseUrl    The configured additional-finder base URL.
     * @param bool   $tokenIsSet Whether a token is configured for this finder (the value is never exposed).
     * @param bool   $active     Whether this additional finder is active (included in the fan-out).
     */
    public function __construct(
        public string $baseUrl,
        public bool $tokenIsSet,
        public bool $active,
    ) {
    }
}
