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
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use RuntimeException;
use Throwable;

use function dirname;
use function hash;
use function is_array;
use function is_file;
use function sprintf;
use function unlink;

/**
 * A file-backed {@see NegativeMemoryStore}: one JSON document per person, named by the SHA-256 of the
 * person id (so an arbitrary xref can never escape the store directory), holding a per-finder map of
 * that person's genuine-miss records (§5.2f) — `{personId, memories: {<finderId>: {signature,
 * recordedAt}}}`. A record merges into the map so one finder's miss never drops another's; a clear
 * removes the whole document. Writes are atomic ({@see AtomicFile}); a read tolerates an absent,
 * corrupt or legacy (pre-§5.2f single-`memory`) document by returning no memory (fail-soft, so a
 * truncated record never breaks the enqueue path and a legacy document simply self-heals on the next
 * search), and the write is capped at the same ceiling the read enforces so a document a capped reader
 * could never read back is never persisted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileNegativeMemoryStore implements NegativeMemoryStore
{
    /**
     * @var int The byte ceiling for a per-person negative-memory document (defence against a corrupt
     *          or maliciously large file). Each per-finder row is tiny; the cap only guards against a
     *          hand-corrupted or symlinked file, and comfortably holds every realistic finder count.
     */
    private const int MAX_BYTES = 65_536;

    /**
     * Constructor.
     *
     * @param string $dir The tree-scoped store directory the negative-memory documents live under.
     */
    public function __construct(
        private string $dir,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string              $personId The person the miss belongs to.
     * @param string              $finderId The identity of the finder whose search came back empty.
     * @param NegativeMemoryEntry $entry    The recorded miss (signature + timestamp).
     *
     * @return void
     */
    public function record(string $personId, string $finderId, NegativeMemoryEntry $entry): void
    {
        $path = $this->pathFor($personId);

        // Read-modify-write so a fresh miss from one finder merges into the person's map without
        // dropping any other finder's memory. Drains run sequentially per tree, so the read-modify-write
        // is not contended; a rare cross-process lost update would only re-search a person once more
        // (negative memory is a self-healing soft cache).
        $memories            = $this->readMemories($path);
        $memories[$finderId] = $entry->toArray();

        AtomicFile::ensureDirectory(dirname($path));
        AtomicFile::writeJson($path, [
            'personId' => $personId,
            'memories' => $memories,
        ], self::MAX_BYTES);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The person whose memory is read.
     * @param string $finderId The finder whose memory is read.
     *
     * @return NegativeMemoryEntry|null The recorded memory, or null.
     */
    public function find(string $personId, string $finderId): ?NegativeMemoryEntry
    {
        $row = $this->readMemories($this->pathFor($personId))[$finderId] ?? null;

        if (!is_array($row)) {
            return null;
        }

        return NegativeMemoryEntry::fromArray($row);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The person whose memory is dropped.
     *
     * @return void
     */
    public function clear(string $personId): void
    {
        $path = $this->pathFor($personId);

        // Best-effort: clearing is soft-cache hygiene (the person has a hit and is already surfaced in
        // the worklist), so a failed unlink — including the warning the webtrees error handler converts
        // into an exception — must never park the drain job or crash the drain. A lingering stale entry
        // only re-suppresses ONE finder for a person already found by another, which the union policy
        // and the person's live match both tolerate.
        try {
            if (is_file($path)) {
                unlink($path);
            }
        } catch (Throwable) {
            // Deliberately swallowed: see the comment above.
        }
    }

    /**
     * Reads a person's raw per-finder memory map from disk, tolerating an absent, corrupt, oversize or
     * legacy (pre-§5.2f single-`memory`) document by returning an empty map. Reading the store's OWN
     * persisted format, so this is a defensive read rather than untrusted-input narrowing.
     *
     * @param string $path The absolute document path.
     *
     * @return array<array-key, mixed> The raw `finderId => row` map, or [] when none is readable.
     */
    private function readMemories(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $memories = AtomicFile::readJsonCapped($path, self::MAX_BYTES)['memories'] ?? null;
        } catch (RuntimeException) {
            // Fail-soft (mirroring the coverage store): a truncated/non-JSON/oversize/symlinked file
            // surfaces as a RuntimeException from readJsonCapped and is treated as "no memory" rather
            // than breaking the enqueue path. A programming error is not swallowed (no catch (Throwable)).
            return [];
        }

        return is_array($memories) ? $memories : [];
    }

    /**
     * Returns the absolute path of the per-person negative-memory document.
     *
     * @param string $personId The person the document belongs to.
     *
     * @return string The absolute document path.
     */
    private function pathFor(string $personId): string
    {
        return sprintf('%s/%s.json', $this->dir, hash('sha256', $personId));
    }
}
