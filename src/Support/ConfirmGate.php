<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

/**
 * Decides whether a match may be confirmed (and written back). The same decision drives the review
 * screen's Confirm button (via the view model) and the handler's live pre-write re-check, so the
 * gate logic lives in exactly one place. A confirm is allowed only when there is no hard conflict,
 * the tree person has no valid death date yet, and the obituary carries an exact day-level date.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ConfirmGate
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Evaluates the confirm gate, returning the decision and the highest-priority reason when denied.
     *
     * @param bool        $hardConflict     Whether the engine flagged a hard conflict.
     * @param bool        $treeHasDeathDate Whether the tree person already has a valid death date.
     * @param string|null $isoDeathDate     The extracted death date (raw, ISO or otherwise).
     *
     * @return ConfirmDecision The gate decision.
     */
    public static function evaluate(bool $hardConflict, bool $treeHasDeathDate, ?string $isoDeathDate): ConfirmDecision
    {
        if ($hardConflict) {
            return new ConfirmDecision(false, 'hard_conflict');
        }

        if ($treeHasDeathDate) {
            return new ConfirmDecision(false, 'tree_already_has_death_date');
        }

        if (!GedcomDateConverter::isValidExactIso($isoDeathDate)) {
            return new ConfirmDecision(false, 'no_exact_death_date');
        }

        return new ConfirmDecision(true, null);
    }
}
