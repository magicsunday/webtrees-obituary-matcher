<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

/**
 * The store-transition consistency gate the Revert CLI applies between the GEDCOM revert
 * ({@see WriteBackReverter::revert()}) and the store transition ({@see \MagicSunday\ObituaryMatcher\Matching\MatchStore::revert()}).
 * The row may be returned to Pending ONLY when no module-written fact still stands in the tree:
 * either every recorded target was deleted (a clean revert, normal or forced), or — under `--force` —
 * the recorded facts were already absent so the revert deleted nothing (orphan repair). A `--force`
 * MIXED partial (some targets deleted, an edited target still standing) is the one outcome that must
 * NOT flip the store to Pending, because the store would then assert a false "Pending" while an edited
 * module-written fact remains in the tree. Static-only utility; it holds no state.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RevertConsistencyGate
{
    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Whether the store may be returned to Pending after the GEDCOM revert. Consistent when every
     * recorded target fact was deleted, OR (only under `--force`) when nothing was deleted at all —
     * the orphan-repair case where the recorded facts were already absent. A `--force` mixed partial
     * (a non-empty subset deleted while an edited target still resolves) is NOT consistent.
     *
     * @param int  $targetCount  The number of recorded target facts (DEAT always, BURI when written).
     * @param int  $deletedCount The number of facts the revert actually deleted.
     * @param bool $force        Whether the revert ran in `--force` mode.
     *
     * @return bool True when the store transition is safe to apply.
     */
    public static function isConsistent(int $targetCount, int $deletedCount, bool $force): bool
    {
        return ($deletedCount === $targetCount)
            || ($force && ($deletedCount === 0));
    }
}
