<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use function is_int;
use function is_string;

/**
 * The matcher's authoritative record that a person was searched under a given {@see SearchSignature}
 * and the search was a genuine miss (every portal searched, nothing found — §5.2d). Together with a
 * TTL and the person's current signature it drives the re-search policy: while the memory is fresh AND
 * still matches what would be searched now, a re-enqueue is suppressed; once the TTL elapses or the
 * person's searchable data changes (a different signature), the person becomes eligible again. Only a
 * genuine miss is ever recorded here — a portal outage (`failed`/`skipped`) is NOT a confirmed miss and
 * must not create a memory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class NegativeMemoryEntry
{
    /**
     * Constructor.
     *
     * @param SearchSignature $signature  The signature of the search that came back empty.
     * @param int             $recordedAt The Unix timestamp the genuine miss was recorded at.
     */
    public function __construct(
        public SearchSignature $signature,
        public int $recordedAt,
    ) {
    }

    /**
     * Serialises this entry to a plain array for the negative-memory store.
     *
     * @return array{signature: string, recordedAt: int} The row.
     */
    public function toArray(): array
    {
        return [
            'signature'  => $this->signature->hash,
            'recordedAt' => $this->recordedAt,
        ];
    }

    /**
     * Rebuilds an entry from a stored row, returning null when the row is corrupt (a missing or
     * non-string signature, or a missing/non-int timestamp) — this reads the store's OWN persisted
     * format, so it is a defensive read rather than untrusted-input narrowing.
     *
     * @param array<array-key, mixed> $row The stored row.
     *
     * @return self|null The entry, or null when the row cannot be rebuilt.
     */
    public static function fromArray(array $row): ?self
    {
        $signature  = $row['signature'] ?? null;
        $recordedAt = $row['recordedAt'] ?? null;

        if (
            !is_string($signature)
            || ($signature === '')
            || !is_int($recordedAt)
        ) {
            return null;
        }

        return new self(new SearchSignature($signature), $recordedAt);
    }
}
