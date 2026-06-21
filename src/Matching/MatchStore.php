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
 * Persistence boundary for scored match suggestions. Rows are keyed by the candidate identifier and
 * the normalised source URL, so two notice links pointing at the same obituary collapse onto one
 * row. The SQL-backed implementation is deferred to Phase 4; Phase 2c ships a file-based store.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface MatchStore
{
    /**
     * Stores a pending suggestion, keyed by its candidate identifier and the normalised source URL.
     * The operation is idempotent: re-ingesting the same notice updates the pending row in place. A
     * row that has already reached a terminal status (Confirmed or Rejected) is left unchanged.
     *
     * @param StoredMatch $match The suggestion to store.
     *
     * @return bool True when a row was actually written, false when the existing row was already
     *              terminal and the upsert was a silent no-op.
     */
    public function upsertPending(StoredMatch $match): bool;

    /**
     * Returns every stored match for the given candidate.
     *
     * @param string $personId The candidate identifier.
     *
     * @return list<StoredMatch> The stored matches, in no guaranteed order.
     */
    public function findByPerson(string $personId): array;

    /**
     * Returns every stored match whose status is Pending.
     *
     * @return list<StoredMatch> The pending matches, in no guaranteed order.
     */
    public function allPending(): array;

    /**
     * Marks the row for the given candidate and source URL as rejected. Rejection is terminal: a
     * later pending upsert for the same key must not resurrect the row.
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source notice URL (raw, pre-normalisation).
     * @param string|null $reason      The rejection reason, if any.
     *
     * @return void
     */
    public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void;
}
