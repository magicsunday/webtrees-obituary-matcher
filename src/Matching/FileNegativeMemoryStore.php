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
use Throwable;

use function hash;
use function is_dir;
use function is_file;
use function rmdir;
use function scandir;
use function sprintf;
use function str_ends_with;
use function unlink;

/**
 * A file-backed {@see NegativeMemoryStore} keyed per (person × finder) (§5.2f): one JSON document per
 * (person, finder) at `<dir>/<sha256(personId)>/<sha256(finderId)>.json`, holding `{personId, finderId,
 * memory: {signature, recordedAt}}`. Both ids are hashed into the path, so an arbitrary xref or finder
 * id can never escape the store directory.
 *
 * A record writes exactly ONE file (its own finder's), never touching another finder's document — so
 * two finders recording a miss for the same person concurrently cannot lose or resurrect each other's
 * entry (there is no shared read-modify-write). A clear removes the person's whole per-finder
 * subdirectory. Writes are atomic ({@see AtomicFile}); a read tolerates an absent, corrupt or legacy
 * (pre-§5.2f single-document) file by returning no memory (fail-soft, so a truncated record never
 * breaks the enqueue path and a legacy layout simply self-heals on the next search), and the write is
 * capped at the same ceiling the read enforces so a document a capped reader could never read back is
 * never persisted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileNegativeMemoryStore implements NegativeMemoryStore
{
    /**
     * @var int The byte ceiling for a single per-(person × finder) negative-memory document (defence
     *          against a corrupt or maliciously large file). A signature+timestamp row is tiny; the cap
     *          only guards against a hand-corrupted or symlinked file.
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
        $path = $this->pathFor($personId, $finderId);

        // One file per (person, finder): the write touches only this finder's document, so it is a
        // whole-file atomic replace with NO read-modify-write — a concurrent record or clear for another
        // finder of the same person can neither lose this entry nor resurrect a cleared one.
        AtomicFile::ensureDirectory($this->dirFor($personId));
        AtomicFile::writeJson($path, [
            'personId' => $personId,
            'finderId' => $finderId,
            'memory'   => $entry->toArray(),
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
        // The shared fail-soft primitive: an absent/truncated/non-JSON/oversize/symlinked file (or a
        // legacy single-document layout with no `memory` object) reads back as null and self-heals on
        // the next drain, rather than breaking the enqueue path. A programming error is not swallowed.
        $row = AtomicFile::readJsonSection($this->pathFor($personId, $finderId), self::MAX_BYTES, 'memory');

        if ($row === null) {
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
        $dir = $this->dirFor($personId);

        // Best-effort: clearing is soft-cache hygiene (the person has a hit and is already surfaced in
        // the worklist), so a failed unlink/rmdir — including a warning the webtrees error handler
        // converts into an exception, or an rmdir that loses to a concurrent finder writing a fresh miss
        // into the directory — must never park the drain job or crash the drain. A lingering entry only
        // re-suppresses ONE finder for a person already found by another, which the person's live match
        // tolerates; a concurrently-written fresh miss surviving the clear is correct (it is genuine).
        try {
            if (!is_dir($dir)) {
                return;
            }

            // scandir (not glob): it lists the literal directory, so a base store path that happens to
            // contain a glob metacharacter ([, ?, *) can never make the listing silently miss files.
            $entries = scandir($dir);

            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (!str_ends_with($entry, '.json')) {
                        continue;
                    }

                    $file = $dir . '/' . $entry;

                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }

            rmdir($dir);
        } catch (Throwable) {
            // Deliberately swallowed: see the comment above.
        }
    }

    /**
     * Returns the absolute path of the per-(person × finder) negative-memory document.
     *
     * @param string $personId The person the document belongs to.
     * @param string $finderId The finder the document belongs to.
     *
     * @return string The absolute document path.
     */
    private function pathFor(string $personId, string $finderId): string
    {
        return sprintf('%s/%s.json', $this->dirFor($personId), hash('sha256', $finderId));
    }

    /**
     * Returns the absolute path of a person's per-finder subdirectory.
     *
     * @param string $personId The person whose subdirectory is addressed.
     *
     * @return string The absolute subdirectory path.
     */
    private function dirFor(string $personId): string
    {
        return sprintf('%s/%s', $this->dir, hash('sha256', $personId));
    }
}
