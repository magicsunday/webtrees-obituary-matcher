<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use RuntimeException;

use function array_map;
use function dirname;
use function hash;
use function is_array;
use function is_file;
use function sprintf;

/**
 * A file-backed {@see CoverageStore}: one JSON document per person, named by the SHA-256 of the person
 * id (so an arbitrary xref can never escape the store directory), holding that person's per-portal
 * coverage. Writes are atomic ({@see AtomicFile}); a read tolerates an absent or corrupt file by
 * returning an empty list, and drops any individual coverage row that no longer rebuilds.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileCoverageStore implements CoverageStore
{
    /**
     * @var int The byte ceiling for a per-person coverage document when read back (defence against a
     *          corrupt or maliciously large file).
     */
    private const int MAX_BYTES = 1_048_576;

    /**
     * Constructor.
     *
     * @param string $dir The tree-scoped store directory the coverage documents live under.
     */
    public function __construct(
        private string $dir,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string               $personId The requested person the coverage belongs to.
     * @param list<PortalCoverage> $coverage The per-portal coverage for that person.
     *
     * @return void
     */
    public function record(string $personId, array $coverage): void
    {
        $path = $this->pathFor($personId);

        AtomicFile::ensureDirectory(dirname($path));

        // Cap the write at the SAME ceiling the read enforces, so a document a capped reader could
        // never read back is never persisted: an oversize coverage payload (e.g. a hostile finder
        // returning thousands of portals) fails loud here in the drain path instead of silently
        // orphaning a file that would break every later tab render for that person.
        AtomicFile::writeJson($path, [
            'personId' => $personId,
            'coverage' => array_map(
                static fn (PortalCoverage $entry): array => $entry->toArray(),
                $coverage,
            ),
        ], self::MAX_BYTES);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The person whose coverage is read.
     *
     * @return list<PortalCoverage> The recorded coverage.
     */
    public function findByPerson(string $personId): array
    {
        $path = $this->pathFor($personId);

        if (!is_file($path)) {
            return [];
        }

        try {
            $rows = AtomicFile::readJsonCapped($path, self::MAX_BYTES)['coverage'] ?? null;
        } catch (RuntimeException) {
            // Honour the fail-soft contract (and mirror FileMatchStore::scanDir): the whole
            // decode/IO corruption class — a truncated or non-JSON file, a symlinked or oversize
            // file — surfaces as a RuntimeException from readJsonCapped and is treated as "no
            // coverage recorded" rather than breaking the person's tab render. A programming error
            // is not swallowed (no catch (Throwable)).
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $coverage = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entry = PortalCoverage::fromArray($row);

            if ($entry instanceof PortalCoverage) {
                $coverage[] = $entry;
            }
        }

        return $coverage;
    }

    /**
     * Returns the absolute path of the per-person coverage document.
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
