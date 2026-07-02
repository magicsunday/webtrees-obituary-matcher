<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;

/**
 * Presents a {@see RevertOutcome} (or the benign not-revertable guard) as a webtrees flash message.
 * Shared by BOTH revert affordances — the tree-wide worklist ({@see ObituaryWorklistHandler}) and the
 * per-match review screen ({@see ReviewScreenHandler}) — so the outcome→flash mapping and its i18n
 * strings live in exactly one place rather than being copied per handler.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RevertFlash
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Maps a {@see RevertOutcome} to its flash message and bootstrap status. `Partial` is unreachable
     * from the force=false UI path (kept defensive); `InvalidWriteBack` IS reachable (a non-null but
     * malformed write-back), so the merged danger arm needs the corrupt-write-back handler test. The
     * match is exhaustive over the reason enum, so no outcome falls through.
     *
     * @param RevertOutcome $outcome The revert outcome to present.
     *
     * @return void
     */
    public static function flashOutcome(RevertOutcome $outcome): void
    {
        [$message, $status] = match ($outcome->reason) {
            RevertReason::Reverted => [
                I18N::plural(
                    'Confirmation reverted; %s fact removed.',
                    'Confirmation reverted; %s facts removed.',
                    $outcome->deletedCount,
                    I18N::number($outcome->deletedCount),
                ),
                'success',
            ],
            RevertReason::RefusedEdited => [
                I18N::translate('Revert refused: a written fact was edited or removed. Use the command-line revert tool with --force to override.'),
                'danger',
            ],
            RevertReason::StoreTransitionFailed => [
                // The recovery genuinely needs --force (else a plain UI/CLI re-run stays RefusedEdited forever).
                I18N::translate('The facts were reverted but the match status could not be updated; please re-run the command-line revert with --force.'),
                'danger',
            ],
            RevertReason::Partial, RevertReason::InvalidWriteBack => [
                I18N::translate('The match could not be reverted.'),
                'danger',
            ],
        };

        FlashMessages::addMessage($message, $status);
    }

    /**
     * Flashes the generic "not revertable" warning — the shared benign guard for a revert requested on
     * a row that is not a revertable Confirmed write-back.
     *
     * @return void
     */
    public static function flashNotRevertable(): void
    {
        FlashMessages::addMessage(I18N::translate('This match cannot be reverted.'), 'warning');
    }
}
