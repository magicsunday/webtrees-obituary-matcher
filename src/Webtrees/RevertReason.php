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
 * The classified outcome of one {@see RevertService::revert()} run. The worklist handler maps it to a
 * flash and the CLI maps it to an exit code, so both presentations read from one source of truth.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum RevertReason
{
    /**
     * The facts were deleted AND the store row returned to Pending.
     */
    case Reverted;

    /**
     * A written fact was edited or already removed; in normal mode the revert deleted nothing.
     */
    case RefusedEdited;

    /**
     * A forced revert deleted some but not all targets; the store was left unchanged.
     */
    case Partial;

    /**
     * The facts were deleted but the store transition failed (the row is out of sync; logged).
     */
    case StoreTransitionFailed;

    /**
     * The confirmed row's recorded write-back is corrupt and could not be parsed.
     */
    case InvalidWriteBack;
}
