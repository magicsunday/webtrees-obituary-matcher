<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use Throwable;

use function count;
use function sprintf;

/**
 * The shared revert orchestration for a single confirmed row, used by both the headless CLI
 * ({@see tools/revert.php}) and the worklist handler ({@see ObituaryWorklistHandler}). It runs the
 * GEDCOM revert ({@see WriteBackReverter}), applies the store-transition consistency gate
 * ({@see RevertConsistencyGate}) and — only when consistent — returns the store row to Pending
 * ({@see MatchStore::revert()}), classifying the result into a {@see RevertOutcome}. The caller resolves
 * the tree, the row and the individual and decides the `$force` mode; this service performs none of the
 * I/O presentation (no STDERR, no exit, no flash) so the two callers map one outcome to their own shape.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RevertService
{
    /**
     * Constructor.
     *
     * @param WriteBackReverter $reverter The GEDCOM reverter; injected so the orchestration test can
     *                                    substitute a double (default is the real reverter).
     */
    public function __construct(
        private WriteBackReverter $reverter = new WriteBackReverter(),
    ) {
    }

    /**
     * Reverts one confirmed row. The caller guarantees the row is Confirmed with a recorded write-back
     * and that `$individual` is the row's person. A corrupt write-back, an edited/removed fact, a forced
     * mixed partial, or a failed store transition each map to their own outcome rather than throwing.
     *
     * @param Individual  $individual The tree person the facts were written to.
     * @param StoredMatch $row        The confirmed store row being reverted.
     * @param MatchStore  $store      The tree-scoped store to transition.
     * @param bool        $force      Whether to skip the all-or-nothing guard (CLI-only).
     *
     * @return RevertOutcome The classified result.
     */
    public function revert(Individual $individual, StoredMatch $row, MatchStore $store, bool $force): RevertOutcome
    {
        $rawWriteBack = $row->writeBack;

        if ($rawWriteBack === null) {
            return RevertOutcome::invalidWriteBack();
        }

        try {
            $writeBack = WriteBack::fromArray($rawWriteBack);
        } catch (InvalidArgumentException) {
            return RevertOutcome::invalidWriteBack();
        }

        // The targets this revert is responsible for: DEAT always, BURI when one was written.
        $targetCount = 1 + (int) ($writeBack->buriFactId !== null) + (int) ($writeBack->cremFactId !== null);

        try {
            $result = $this->reverter->revert($individual, $writeBack, $force);
        } catch (RevertPreconditionException) {
            return RevertOutcome::refusedEdited();
        }

        $deletedCount = count($result->deletedFactIds);

        if (!RevertConsistencyGate::isConsistent($targetCount, $deletedCount, $force)) {
            return RevertOutcome::partial($deletedCount, $targetCount);
        }

        try {
            $store->revert($row->personId, $row->obituaryUrl);
        } catch (Throwable $throwable) {
            Log::addErrorLog(sprintf('Obituary matcher: revert deleted the facts but the store transition failed for person %s (%s).', $row->personId, $throwable::class));

            return RevertOutcome::storeTransitionFailed($deletedCount, $targetCount);
        }

        return RevertOutcome::reverted($deletedCount);
    }
}
