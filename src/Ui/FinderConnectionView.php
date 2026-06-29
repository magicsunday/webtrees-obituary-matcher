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
 * The webtrees-free AND Queue-free projection of the persisted finder connection for the control
 * panel. It carries the selected transport, the configured base URL and a token-is-set boolean — the
 * token VALUE never reaches a view. An optional probe readout accompanies it after a reachability
 * test; a plain GET render carries none. The template escapes every sink once with e().
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
     * @param string                $transport  The selected finder transport (`file`|`rest`).
     * @param string                $baseUrl    The configured finder base URL (empty when unset).
     * @param bool                  $tokenIsSet Whether an authentication token is configured (the value is never exposed).
     * @param ProbeReadoutView|null $probe      The accompanying probe readout, or null when none was run.
     */
    public function __construct(
        public string $transport,
        public string $baseUrl,
        public bool $tokenIsSet,
        public ?ProbeReadoutView $probe,
    ) {
    }
}
