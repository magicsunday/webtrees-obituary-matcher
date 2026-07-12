<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use FilesystemIterator;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Support\CoverageMerge;
use SplFileInfo;
use UnexpectedValueException;

use function array_map;
use function hash;
use function is_array;
use function is_string;
use function pathinfo;
use function sprintf;

use const PATHINFO_EXTENSION;

/**
 * A file-backed {@see CoverageStore} keyed per (person × finder) (§5.2c): one JSON document per (person,
 * finder) at `<dir>/<sha256(personId)>/<sha256(finderId)>.json`, holding `{personId, finderId, coverage:
 * [...]}`. Both ids are hashed into the path, so an arbitrary xref or finder id can never escape the
 * store directory.
 *
 * A record writes exactly ONE file (its own finder's), never touching another finder's document — so two
 * finders recording coverage for the same person concurrently cannot clobber each other (there is no
 * shared read-modify-write). A read scans the person's subdirectory and UNIONS every finder's rows into
 * one row per portal ({@see CoverageMerge}): a portal reads as covered if ANY finder covered it. Writes
 * are atomic ({@see AtomicFile}); a read tolerates an absent, corrupt or legacy (pre-§5.2c single-
 * document) file by treating it as no coverage (fail-soft, so a truncated record never breaks the
 * person's tab render and a legacy layout self-heals on the next drain), and the write is capped at the
 * same ceiling the read enforces so a document a capped reader could never read back is never persisted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileCoverageStore implements CoverageStore
{
    /**
     * @var int The byte ceiling for a single per-(person × finder) coverage document when read back
     *          (defence against a corrupt or maliciously large file).
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
     * @param string               $finderId The identity of the finder that reported this coverage.
     * @param list<PortalCoverage> $coverage The per-portal coverage for that person.
     *
     * @return void
     */
    public function record(string $personId, string $finderId, array $coverage): void
    {
        $path = $this->pathFor($personId, $finderId);

        AtomicFile::ensureDirectory($this->dirFor($personId));

        // One file per (person, finder): a whole-file atomic replace with NO read-modify-write, so a
        // concurrent record for another finder of the same person cannot clobber this coverage. Capped at
        // the SAME ceiling the read enforces, so an oversize payload (a hostile finder returning thousands
        // of portals) fails loud here in the drain path instead of orphaning a file the reader can never
        // read back.
        AtomicFile::writeJson($path, [
            'personId' => $personId,
            'finderId' => $finderId,
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
     * @return list<PortalCoverage> The merged coverage, one row per portal.
     */
    public function findByPerson(string $personId): array
    {
        // A FilesystemIterator (not scandir) so an existing-but-unreadable person directory throws a
        // TYPED UnexpectedValueException HERE — rather than an E_WARNING the webtrees error handler would
        // convert into an exception that escapes this render path and crashes the individual tab — and so
        // a glob metacharacter in the base store path can never mis-scan. Mirrors FileMatchStore::scanDir,
        // the established fail-soft render-scan idiom.
        try {
            $iterator = new FilesystemIterator($this->dirFor($personId), FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            // The person's sub-directory does not exist yet, or is unreadable: no coverage recorded.
            return [];
        }

        $rows = [];

        // Union every finder's coverage document for this person, requiring each document's own stored
        // personId to equal $personId (readCoverageDoc normalises an absent, empty or non-string id to
        // null, which never equals a real xref, so it too is dropped). This is the exact per-person
        // equivalent of the hash-to-directory guard each() applies — record() always writes the correct
        // personId, so a document that fails this check hashed into the directory by corruption and must
        // not be attributed to $personId. Keeping both reads on the same strict rule guarantees the
        // individual tab and the tree-wide worklist agree on a person's coverage under corruption.
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $result = $this->readCoverageDoc($fileInfo);

            if (($result === null) || ($result['personId'] !== $personId)) {
                continue;
            }

            foreach ($result['coverage'] as $row) {
                $rows[] = $row;
            }
        }

        return CoverageMerge::union($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<string, list<PortalCoverage>> Each searched personId mapped to their merged per-portal coverage.
     */
    public function each(): iterable
    {
        // A FilesystemIterator (typed UnexpectedValueException, no E_WARNING the webtrees handler would
        // convert into an escaping exception) over the store root: the person sub-directories.
        try {
            $subdirs = new FilesystemIterator($this->dir, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            // The store directory does not exist yet: nothing has been searched.
            return;
        }

        foreach ($subdirs as $subdir) {
            if (!$subdir instanceof SplFileInfo) {
                continue;
            }

            // A legacy pre-§5.2c root-level "<sha(personId)>.json" document is a FILE, not a
            // sub-directory, so it is skipped here before the hash guard — never surfaced tree-wide.
            if (!$subdir->isDir()) {
                continue;
            }

            // Skip a symlinked directory even when isDir() reports true THROUGH the link, so the tree-wide
            // scan can never follow a symlink out of the store and read coverage documents elsewhere on
            // disk. Mirrors FileMatchStore::allRows()'s !isLink() guard; readCoverageDoc rejects a symlinked
            // *file* but NOT a regular file reached through a symlinked *parent*, so this guard is
            // load-bearing.
            if ($subdir->isLink()) {
                continue;
            }

            try {
                $docs = new FilesystemIterator($subdir->getPathname(), FilesystemIterator::SKIP_DOTS);
            } catch (UnexpectedValueException) {
                // The sub-directory vanished between the listing and this scan (TOCTOU): skip it.
                continue;
            }

            $rows     = [];
            $personId = null;

            foreach ($docs as $doc) {
                if (!$doc instanceof SplFileInfo) {
                    continue;
                }

                $result = $this->readCoverageDoc($doc);

                if ($result === null) {
                    continue;
                }

                // Per-document integrity guard: the document's OWN personId must hash to the containing
                // directory (dir === sha256(personId)). A document with no personId, or one whose id
                // hashes elsewhere (misplaced/corrupt), is dropped — its coverage must NOT be attributed
                // to this person, whom the consumer would otherwise link. Validated documents all carry
                // the same id (they hash to the same directory), so $personId stays coherent.
                if ($result['personId'] === null) {
                    continue;
                }

                if (hash('sha256', $result['personId']) !== $subdir->getFilename()) {
                    continue;
                }

                $personId = $result['personId'];

                foreach ($result['coverage'] as $row) {
                    $rows[] = $row;
                }
            }

            if ($personId === null) {
                // No validated document in this directory: nothing to surface tree-wide.
                continue;
            }

            yield $personId => CoverageMerge::union($rows);
        }
    }

    /**
     * Reads one finder's coverage document, tolerating an absent, corrupt or oversize file (via the
     * shared {@see AtomicFile::readJsonDocument} fail-soft primitive) by returning null and dropping any
     * individual row that no longer rebuilds. Returns the document's stored personId (a non-empty string,
     * or null when it carries none) alongside its coverage rows, so the caller can apply its own identity
     * policy — {@see self::findByPerson} ignores it, {@see self::each} validates it. Reading the store's
     * OWN persisted format, so this is a defensive read rather than untrusted-input narrowing. Only
     * "*.json" documents are read: an in-flight atomic temp file is "<hash>.json.tmp.<uniqid>", whose
     * extension is the uniqid (not "json"), so it is skipped.
     *
     * @param SplFileInfo $fileInfo The directory entry to read.
     *
     * @return array{personId: string|null, coverage: list<PortalCoverage>}|null The document, or null
     *                                                                           when it is not a readable
     *                                                                           JSON coverage document.
     */
    private function readCoverageDoc(SplFileInfo $fileInfo): ?array
    {
        if (pathinfo($fileInfo->getPathname(), PATHINFO_EXTENSION) !== 'json') {
            return null;
        }

        $document = AtomicFile::readJsonDocument($fileInfo->getPathname(), self::MAX_BYTES);

        if ($document === null) {
            return null;
        }

        $personId = $document['personId'] ?? null;
        $rows     = $document['coverage'] ?? null;

        $coverage = [];

        // Guard the coverage value with is_array before iterating: readJsonDocument returns the whole
        // document, so a scalar "coverage" must not be iterated (readJsonSection gave this guarantee for
        // free; the whole-document read restores it here).
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $entry = PortalCoverage::fromArray($row);

                if ($entry instanceof PortalCoverage) {
                    $coverage[] = $entry;
                }
            }
        }

        return [
            'personId' => (is_string($personId) && ($personId !== '')) ? $personId : null,
            'coverage' => $coverage,
        ];
    }

    /**
     * Returns the absolute path of the per-(person × finder) coverage document.
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
     * Returns the absolute path of a person's per-finder coverage subdirectory.
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
