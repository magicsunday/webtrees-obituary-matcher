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
 * The decision of {@see ConfirmGate}: whether a match may be confirmed, and if not, the
 * highest-priority machine-readable reason key the UI translates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ConfirmDecision
{
    /**
     * Constructor.
     *
     * @param bool        $canConfirm Whether the match may be confirmed.
     * @param string|null $reasonKey  The disabled-reason key when not, else null.
     */
    public function __construct(
        public bool $canConfirm,
        public ?string $reasonKey,
    ) {
    }
}
