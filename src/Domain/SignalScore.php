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
 * The raw contribution of one signal to the total match score.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class SignalScore
{
    /**
     * Constructor.
     *
     * @param int          $score   Points awarded for this signal.
     * @param int          $max     Maximum points this signal can contribute.
     * @param list<string> $reasons Human-readable explanations for the awarded points.
     */
    public function __construct(
        public int $score,
        public int $max,
        public array $reasons,
    ) {
    }
}
