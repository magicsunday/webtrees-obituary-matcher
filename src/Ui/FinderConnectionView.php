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
 * The webtrees-free AND Queue-free projection of the persisted REST finder connection for the control
 * panel. It carries the primary connection's base URL, a token-is-set boolean (the token VALUE never
 * reaches a view) and the list of ADDITIONAL finders (§5.2f increment 2) the fan-out composes over. An
 * optional probe readout accompanies it after a reachability test; a plain GET render carries none. The
 * template escapes every sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderConnectionView
{
    /**
     * Constructor.
     *
     * @param string                        $baseUrl    The configured primary finder base URL (empty when unset).
     * @param bool                          $tokenIsSet Whether a primary token is configured (the value is never exposed).
     * @param ProbeReadoutView|null         $probe      The accompanying probe readout, or null when none was run.
     * @param list<AdditionalFinderRowView> $additional The configured additional finders (active and inactive).
     */
    public function __construct(
        public string $baseUrl,
        public bool $tokenIsSet,
        public ?ProbeReadoutView $probe,
        public array $additional = [],
    ) {
    }
}
