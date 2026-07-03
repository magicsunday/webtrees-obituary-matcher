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
 * A view-ready tree family member for the family-graph panel: the raw {@see TreeFamilyMember} paired
 * with whether a notice relative loosely corresponds to it. An UNmatched member is NOT a conflict — the
 * tree may simply be incomplete — so the panel renders it neutrally, only highlighting the matched ones.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FamilyMemberView
{
    /**
     * Constructor.
     *
     * @param string $name        The member's plain display name.
     * @param string $relationKey The relation to the tree person: `spouse`, `child` or `parent`.
     * @param bool   $matched     Whether a notice relative loosely corresponds to this member.
     */
    public function __construct(
        public string $name,
        public string $relationKey,
        public bool $matched,
    ) {
    }
}
