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
 * The webtrees-free projection of the worklist's candidate-selection filter (#63): the age window
 * (min / optional max), the include-unknown-birth toggle, and — only when a preview was requested — the
 * "≈ N people match" count of individuals the selection would search. It carries plain scalars the
 * template escapes once with e(); it never reaches the privacy gate, which lives in the repository and
 * always runs regardless of these values, so a filter can steer WHO is previewed but can never loosen
 * who is visible.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CandidateFilterView
{
    /**
     * Constructor.
     *
     * @param int      $minAge               The minimum age currently entered in the filter.
     * @param int|null $maxAge               The maximum age entered, or null when no upper bound is set.
     * @param bool     $includeUnknownBirth  Whether individuals without a known birth date are included.
     * @param int|null $matchCount           The count of individuals the selection would search, or null
     *                                       when no preview was requested (a plain worklist render).
     * @param bool     $matchCountReachedCap Whether the count hit the defensive cap (so it is a lower
     *                                       bound, rendered as "N+"); always false when no preview ran.
     */
    public function __construct(
        public int $minAge,
        public ?int $maxAge,
        public bool $includeUnknownBirth,
        public ?int $matchCount,
        public bool $matchCountReachedCap = false,
    ) {
    }
}
