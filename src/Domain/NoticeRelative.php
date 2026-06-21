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
 * A relative named in a notice, with a best-effort relationship guess and a confidence.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class NoticeRelative
{
    /**
     * Constructor.
     *
     * @param string $name          Raw name of the relative as written in the notice.
     * @param string $relationGuess Best-effort relationship label (e.g. "spouse", "child").
     * @param float  $confidence    Confidence of the relationship guess, from 0.0 to 1.0.
     */
    public function __construct(
        public string $name,
        public string $relationGuess,
        public float $confidence,
    ) {
    }
}
