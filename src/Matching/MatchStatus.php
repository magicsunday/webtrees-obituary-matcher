<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

/**
 * The lifecycle state of a stored match. A freshly ingested suggestion starts as Pending and is
 * later confirmed, rejected or flagged as uncertain by the review workflow. Confirmed and Rejected
 * are terminal: once reached, an idempotent re-ingest must not overwrite them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum MatchStatus: string
{
    /**
     * A scored suggestion awaiting human review.
     */
    case Pending = 'pending';

    /**
     * A confirmed match (terminal).
     */
    case Confirmed = 'confirmed';

    /**
     * A rejected match (terminal).
     */
    case Rejected = 'rejected';

    /**
     * A match the reviewer could not decide; not terminal.
     */
    case Uncertain = 'uncertain';

    /**
     * Returns whether this status is terminal and may not be overwritten by a re-ingest.
     *
     * @return bool True for Confirmed and Rejected.
     */
    public function isTerminal(): bool
    {
        return ($this === self::Confirmed)
            || ($this === self::Rejected);
    }
}
