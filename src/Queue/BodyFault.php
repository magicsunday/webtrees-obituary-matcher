<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

/**
 * Classifies WHY {@see CappedJsonBodyReader::decode()} could not produce a usable JSON object, so a
 * caller can distinguish a fault a later re-GET may recover from one the contract will reproduce
 * verbatim forever. Collapsing every failure to a single "unusable" signal made a permanently broken
 * response indistinguishable from a transient one, so it was polled forever instead of terminally
 * failed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum BodyFault
{
    /**
     * A torn/interrupted read (a connection dropped mid-body) that a later re-GET may recover: the
     * caller keeps its ledger entry and retries on the next drain.
     */
    case Transient;

    /**
     * An oversize, malformed or non-object body the contract will reproduce verbatim on every re-GET:
     * the caller must terminally fail it rather than poll it forever.
     */
    case Permanent;
}
