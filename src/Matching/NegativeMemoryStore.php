<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\NegativeMemoryEntry;

/**
 * The negative-memory persistence boundary, keyed per (person × finder) (§5.2d/§5.2f): the matcher
 * records "finder F searched this person under this signature and found nothing" and later reads it
 * back to drive that finder's re-search policy. Keying on the finder is the multi-finder fix — one
 * finder's genuine miss must never make the matcher believe a DIFFERENT finder already searched the
 * person. Kept a distinct seam from the {@see MatchStore} (per notice) and the {@see CoverageStore}
 * (per portal) so each store owns exactly one concern. The finder stays stateless — this authoritative
 * memory lives entirely in the matcher.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface NegativeMemoryStore
{
    /**
     * Records (last-write-wins for that finder) that a finder's search of a person under the entry's
     * signature was a genuine miss, leaving every OTHER finder's memory for the person untouched.
     *
     * @param string              $personId The person the miss belongs to.
     * @param string              $finderId The identity of the finder whose search came back empty.
     * @param NegativeMemoryEntry $entry    The recorded miss (signature + timestamp).
     *
     * @return void
     */
    public function record(string $personId, string $finderId, NegativeMemoryEntry $entry): void;

    /**
     * Returns the last-recorded negative memory of a single finder for a person, or null when that
     * finder recorded no miss (it never searched the person, the person was a hit, or the record is
     * absent/corrupt/legacy).
     *
     * @param string $personId The person whose memory is read.
     * @param string $finderId The finder whose memory is read.
     *
     * @return NegativeMemoryEntry|null The recorded memory, or null.
     */
    public function find(string $personId, string $finderId): ?NegativeMemoryEntry;

    /**
     * Drops every finder's negative memory for a person. Called when any finder finds a notice for the
     * person: a person with a hit is no longer a nothing-found case, so a stale miss from another finder
     * must not keep suppressing.
     *
     * @param string $personId The person whose memory is dropped.
     *
     * @return void
     */
    public function clear(string $personId): void;
}
