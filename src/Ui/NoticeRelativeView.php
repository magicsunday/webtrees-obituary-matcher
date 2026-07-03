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
 * A view-ready notice relative for the family-graph panel: the persisted name and relation guess, the
 * finder's extraction confidence flagged as `uncertain` when it is below the display threshold, and
 * whether a tree family member loosely corresponds to it. A relative with no tree match is NOT a
 * conflict — it is simply shown unhighlighted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class NoticeRelativeView
{
    /**
     * Constructor.
     *
     * @param string $name          The relative's plain name as printed in the notice.
     * @param string $relationGuess The finder's best-effort relationship label (`spouse`, `child`, …).
     * @param bool   $uncertain     Whether the extraction confidence is below the display threshold.
     * @param bool   $matched       Whether a tree family member loosely corresponds to this relative.
     */
    public function __construct(
        public string $name,
        public string $relationGuess,
        public bool $uncertain,
        public bool $matched,
    ) {
    }
}
