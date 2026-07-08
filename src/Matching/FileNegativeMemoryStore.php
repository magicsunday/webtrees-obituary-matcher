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

use function dirname;
use function hash;
use function is_array;
use function is_file;
use function sprintf;

/**
 * A file-backed {@see NegativeMemoryStore}: one JSON document per person, named by the SHA-256 of the
 * person id (so an arbitrary xref can never escape the store directory), holding that person's last
 * genuine-miss record. Writes are atomic ({@see AtomicFile}); a read tolerates an absent or corrupt
 * file by returning null (fail-soft, so a truncated record never breaks the enqueue path), and the
 * write is capped at the same ceiling the read enforces so a document a capped reader could never read
 * back is never persisted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileNegativeMemoryStore implements NegativeMemoryStore
{
    /**
     * @var int The byte ceiling for a per-person negative-memory document (defence against a corrupt
     *          or maliciously large file). A single signature+timestamp row is tiny; the cap only
     *          guards against a hand-corrupted or symlinked file.
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
     * @param NegativeMemoryEntry $entry    The recorded miss (signature + timestamp).
     *
     * @return void
     */
    public function record(string $personId, NegativeMemoryEntry $entry): void
    {
        $path = $this->pathFor($personId);

        AtomicFile::ensureDirectory(dirname($path));
        AtomicFile::writeJson($path, [
            'personId' => $personId,
            'memory'   => $entry->toArray(),
        ], self::MAX_BYTES);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The person whose memory is read.
     *
     * @return NegativeMemoryEntry|null The recorded memory, or null.
     */
    public function find(string $personId): ?NegativeMemoryEntry
    {
        $path = $this->pathFor($personId);

        if (!is_file($path)) {
            return null;
        }

        try {
            $row = AtomicFile::readJsonCapped($path, self::MAX_BYTES)['memory'] ?? null;
        } catch (RuntimeException) {
            // Fail-soft (mirroring FileCoverageStore): a truncated/non-JSON/oversize/symlinked file
            // surfaces as a RuntimeException from readJsonCapped and is treated as "no memory" rather
            // than breaking the enqueue path. A programming error is not swallowed (no catch (Throwable)).
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        return NegativeMemoryEntry::fromArray($row);
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
