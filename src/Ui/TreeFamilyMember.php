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
 * A webtrees-free projection of ONE core-family member of the tree person (a spouse, child or parent),
 * built by the review-screen handler from the live individual and carried on {@see TreePersonView}. It
 * is the raw tree side of the family-graph panel: the view model pairs it against the notice relatives
 * to compute the matched flags rendered as {@see FamilyMemberView}s.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class TreeFamilyMember
{
    /**
     * Constructor.
     *
     * @param string $name        The member's plain display name (webtrees markup already stripped).
     * @param string $relationKey The relation to the tree person: `spouse`, `child` or `parent`.
     */
    public function __construct(
        public string $name,
        public string $relationKey,
    ) {
    }
}
