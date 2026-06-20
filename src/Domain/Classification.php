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
 * The final qualitative classification of a match result.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class Classification
{
    /**
     * Constructor.
     *
     * @param Band         $band      The confidence band for this classification.
     * @param bool         $ambiguous Whether multiple candidates scored within the ambiguity gap.
     * @param list<string> $reasons   Human-readable explanations for the assigned band.
     */
    public function __construct(
        public Band $band,
        public bool $ambiguous,
        public array $reasons,
    ) {
    }
}
