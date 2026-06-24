<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;

/**
 * Reverses a confirmed GEDCOM write-back by deleting the facts it wrote, keeping the portal source.
 * A captured fact id is the md5 of the fact gedcom, so it resolves on the individual only while the
 * fact is byte-identical to what was written — id-resolution is the tamper-check.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class WriteBackReverter
{
    /**
     * Reverses a confirmed write-back: deletes the written DEAT and (when present) BURI fact, keeping
     * the portal source. A captured fact id resolves only when the fact is byte-identical to what was
     * written (the id is the md5 of the fact gedcom), so an unresolved target means the fact was edited
     * or already removed. Without $force the revert is all-or-nothing (refuse on any unresolved target,
     * delete nothing); with $force it best-effort deletes the targets that still resolve.
     *
     * @param Individual $individual The tree person the facts were written to.
     * @param WriteBack  $writeBack  The recorded write-back (the fact ids to delete).
     * @param bool       $force      Skip the all-or-nothing guard; delete whichever targets resolve.
     *
     * @return RevertResult The ids of the facts actually deleted.
     *
     * @throws RevertPreconditionException When a target fact is missing/edited and $force is false.
     */
    public function revert(Individual $individual, WriteBack $writeBack, bool $force = false): RevertResult
    {
        $targetIds = [$writeBack->deatFactId];

        if ($writeBack->buriFactId !== null) {
            $targetIds[] = $writeBack->buriFactId;
        }

        $resolved = [];
        $missing  = [];

        foreach ($targetIds as $targetId) {
            if ($this->factExists($individual, $targetId)) {
                $resolved[] = $targetId;
            } else {
                $missing[] = $targetId;
            }
        }

        if (
            ($force === false)
            && ($missing !== [])
        ) {
            throw new RevertPreconditionException(
                'A written fact was edited or already removed since the confirm; nothing was reverted.'
            );
        }

        foreach ($resolved as $factId) {
            $individual->deleteFact($factId, true);
        }

        return new RevertResult($resolved);
    }

    /**
     * Whether the individual still carries a fact with the given id (id = md5 of the fact gedcom, so
     * this is true only for a byte-identical, un-edited fact). Uses the same facts() call deleteFact
     * iterates, so a found id is guaranteed deletable.
     *
     * @param Individual $individual The tree person.
     * @param string     $factId     The captured fact id.
     *
     * @return bool True when a fact with that exact id is present.
     */
    private function factExists(Individual $individual, string $factId): bool
    {
        foreach ($individual->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            if ($fact->id() === $factId) {
                return true;
            }
        }

        return false;
    }
}
