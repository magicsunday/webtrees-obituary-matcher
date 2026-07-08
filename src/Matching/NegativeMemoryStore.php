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
 * The per-person negative-memory persistence boundary (§5.2d): the matcher records "searched under
 * this signature, found nothing" for a person and later reads it back to drive the re-search policy.
 * Kept a distinct seam from the {@see MatchStore} (per notice) and the {@see CoverageStore} (per
 * portal) so each store owns exactly one concern; a consumer needing only the negative memory depends
 * on just this. The finder stays stateless — this authoritative memory lives entirely in the matcher.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface NegativeMemoryStore
{
    /**
     * Records (last-write-wins) that a person's search under the entry's signature was a genuine miss.
     *
     * @param string              $personId The person the miss belongs to.
     * @param NegativeMemoryEntry $entry    The recorded miss (signature + timestamp).
     *
     * @return void
     */
    public function record(string $personId, NegativeMemoryEntry $entry): void;

    /**
     * Returns the last-recorded negative memory for a person, or null when none was recorded (the
     * person was never a genuine miss, or the record is absent/corrupt).
     *
     * @param string $personId The person whose memory is read.
     *
     * @return NegativeMemoryEntry|null The recorded memory, or null.
     */
    public function find(string $personId): ?NegativeMemoryEntry;
}
