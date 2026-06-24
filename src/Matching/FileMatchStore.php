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
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

use function hash;
use function is_file;
use function pathinfo;
use function preg_match;
use function sprintf;

use const PATHINFO_EXTENSION;

/**
 * A file-based {@see MatchStore}: one atomic JSON file per (candidate, normalised URL) key, grouped
 * under a per-candidate sub-directory ({@see dirForPerson()}). The sub-directory name is a content
 * hash of the candidate identifier and the file name is the URL identity key, so two notice links
 * pointing at the same obituary collapse onto one file and no candidate identifier ever reaches the
 * filesystem (avoiding any xref-charset escaping). Because every row for a candidate lives in that
 * candidate's own sub-directory, {@see findByPerson()} scans ONLY that sub-directory — O(rows for the
 * candidate) rather than O(whole store) — filtering on the decoded personId as defence-in-depth against
 * a misplaced/corrupt row in the wrong sub-directory. The tree-wide {@see allPending()} worklist recurses one
 * level across the sub-directories. The candidate identifier and status are persisted inside each
 * row, so the decoded row content carries the authoritative state.
 *
 * SQL-backed persistence is deferred to Phase 4; this store carries Phase 2c.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileMatchStore implements MatchStore
{
    /**
     * The byte cap applied when reading a stored row back from disk.
     */
    private const int MAX_BYTES = 1_048_576;

    /**
     * Constructor.
     *
     * @param string $dir The store root directory holding one per-candidate sub-directory of JSON rows.
     */
    public function __construct(
        private string $dir,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param StoredMatch $match The suggestion to store.
     *
     * @return bool True when a row was actually written, false when the existing row was already
     *              terminal and the upsert was a silent no-op.
     */
    public function upsertPending(StoredMatch $match): bool
    {
        $path = $this->pathFor($match->personId, $match->obituaryUrl);

        $existing = $this->readRow($path);

        if (
            ($existing instanceof StoredMatch)
            && $existing->status->isTerminal()
        ) {
            return false;
        }

        $this->ensureLayout($match->personId);

        AtomicFile::writeJson($path, $match->toArray());

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The candidate identifier.
     *
     * @return list<StoredMatch> The stored matches, in no guaranteed order.
     */
    public function findByPerson(string $personId): array
    {
        $matches = [];

        // The candidate's rows all live in its own sub-directory by construction, so scanning ONLY that
        // sub-directory is O(rows for this candidate) — not a whole-store scan. The personId equality is
        // defence-in-depth: it restores the pre-refactor flat store's filter so a manually-misplaced or
        // corrupt row sitting in the WRONG sub-directory is never returned as this candidate's.
        foreach ($this->scanDir($this->dirForPerson($personId)) as $row) {
            if ($row->personId === $personId) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<StoredMatch> The pending matches, in no guaranteed order.
     */
    public function allPending(): array
    {
        $matches = [];

        foreach ($this->allRows() as $row) {
            if ($row->status === MatchStatus::Pending) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $personId The candidate identifier.
     * @param string $rowKey   The canonical row key.
     *
     * @return StoredMatch|null The stored row, or null when absent.
     */
    public function findOne(string $personId, string $rowKey): ?StoredMatch
    {
        // The fail-loud single-key read (readRow) matches markRejected's semantics: a corrupt target
        // throws rather than masquerading as "not found".
        return $this->readRow($this->pathForRowKey($personId, $rowKey));
    }

    /**
     * {@inheritDoc}
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source notice URL (raw, pre-normalisation).
     * @param string|null $reason      The rejection reason, if any.
     *
     * @return void
     *
     * @throws TerminalMatchTransitionException When the existing row is already Confirmed: an
     *                                          explicit rejection of a confirmed match must be
     *                                          surfaced, not silently dropped.
     */
    public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
    {
        $path     = $this->pathFor($personId, $obituaryUrl);
        $existing = $this->readRow($path);

        if ($existing instanceof StoredMatch) {
            if ($existing->status === MatchStatus::Confirmed) {
                // A Confirmed row stays terminal, but an EXPLICIT rejection (unlike an automated
                // re-ingest) must not vanish silently: surface the refusal so the caller can react
                // (for example, prompt the user to un-confirm first).
                throw new TerminalMatchTransitionException(
                    sprintf(
                        'Cannot reject match for person %s: it is already confirmed (un-confirm first).',
                        $personId
                    )
                );
            }

            if (
                ($existing->status === MatchStatus::Rejected)
                && ($existing->reason === $reason)
            ) {
                // Already rejected with the same reason: re-rejecting is a harmless, idempotent
                // no-op. The terminal guard only stops an AUTOMATED re-ingest (see upsertPending)
                // from resurrecting a decision; a re-reject with a DIFFERENT reason falls through to
                // re-write the row (still Rejected, just an updated reviewer reason).
                return;
            }

            $match     = $existing->match;
            $writeBack = $existing->writeBack;
        } else {
            $match     = ClassifiedMatch::emptyArray($personId, $obituaryUrl);
            $writeBack = null;
        }

        $rejected = new StoredMatch(
            $personId,
            $obituaryUrl,
            MatchStatus::Rejected,
            $match,
            $reason,
            $writeBack,
        );

        $this->ensureLayout($personId);

        AtomicFile::writeJson($path, $rejected->toArray());
    }

    /**
     * {@inheritDoc}
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source notice URL (raw, pre-normalisation).
     * @param string|null $reason      The reviewer note, if any.
     *
     * @return void
     *
     * @throws TerminalMatchTransitionException When the current row is already terminal.
     */
    public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
    {
        $path     = $this->pathForRowKey($personId, StoredMatchKey::fromUrl($obituaryUrl));
        $existing = $this->readRow($path);

        if (!$existing instanceof StoredMatch) {
            // Nothing to mark: the row vanished (a concurrent delete). "Uncertain" is meaningless
            // without an existing match, so this is a no-op — NOT a synthetic empty row. (This is a
            // deliberate divergence from markRejected, whose empty tombstone blocks a re-ingest from
            // resurrecting a rejected decision; uncertain has no such dedup purpose.)
            return;
        }

        if ($existing->status->isTerminal()) {
            throw new TerminalMatchTransitionException(
                sprintf(
                    'Cannot mark match for person %s uncertain: it is already %s.',
                    $personId,
                    $existing->status->value
                )
            );
        }

        if (
            ($existing->status === MatchStatus::Uncertain)
            && ($existing->reason === $reason)
        ) {
            // Already uncertain with the same reviewer note: an idempotent no-op, no rewrite, so the
            // mtime-based tab/asset cache stays stable.
            return;
        }

        $uncertain = new StoredMatch(
            $personId,
            $obituaryUrl,
            MatchStatus::Uncertain,
            $existing->match,
            $reason,
            $existing->writeBack,
        );

        $this->ensureLayout($personId);

        AtomicFile::writeJson($path, $uncertain->toArray());
    }

    /**
     * {@inheritDoc}
     *
     * @param string    $personId    The candidate identifier.
     * @param string    $obituaryUrl The source notice URL (raw, pre-normalisation).
     * @param WriteBack $writeBack   The IDs of the records written to the tree.
     *
     * @return bool True when the row transitioned; false when already confirmed or absent.
     *
     * @throws TerminalMatchTransitionException When the row is already rejected.
     */
    public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
    {
        $path     = $this->pathForRowKey($personId, StoredMatchKey::fromUrl($obituaryUrl));
        $existing = $this->readRow($path);

        if (!$existing instanceof StoredMatch) {
            // The row vanished (a concurrent delete). Confirming nothing is a no-op, not a synthetic row.
            return false;
        }

        if ($existing->status === MatchStatus::Confirmed) {
            // Already confirmed: idempotent no-op. The existing write-back is the source of truth for
            // a later revert and must never be overwritten — terminal stays terminal.
            return false;
        }

        if ($existing->status === MatchStatus::Rejected) {
            throw new TerminalMatchTransitionException(
                sprintf('Cannot confirm match for person %s: it is already rejected.', $personId)
            );
        }

        $confirmed = new StoredMatch(
            $personId,
            $obituaryUrl,
            MatchStatus::Confirmed,
            $existing->match,
            $existing->reason,
            $writeBack->toArray(),
        );

        $this->ensureLayout($personId);

        AtomicFile::writeJson($path, $confirmed->toArray());

        return true;
    }

    /**
     * Returns the absolute path of the JSON row for the given (candidate, row key) pair: the candidate
     * keys its own sub-directory ({@see dirForPerson()}) and the bare row key is the file name.
     *
     * @param string $personId The candidate identifier.
     * @param string $rowKey   The canonical row key (SHA-256 of the identity-normalised URL).
     *
     * @return string The absolute row path.
     *
     * @throws InvalidArgumentException When the row key is not a 64-character lowercase hex string.
     */
    private function pathForRowKey(string $personId, string $rowKey): string
    {
        // Defence-in-depth at the path sink: the candidate identifier is hashed in dirForPerson, but
        // the row key is used RAW as the file name and is the only untrusted-shaped path component.
        // Every current caller passes a 64-hex SHA-256 key, so reject anything else here so a future
        // caller cannot smuggle a traversal sequence (or a trailing newline past "$") into the path.
        if (preg_match('/^[0-9a-f]{64}$/D', $rowKey) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid row key: %s', $rowKey));
        }

        return sprintf('%s/%s.json', $this->dirForPerson($personId), $rowKey);
    }

    /**
     * Returns the absolute path of the per-candidate sub-directory holding that candidate's rows. The
     * candidate identifier is hashed so no raw xref ever reaches the filesystem (no charset escaping).
     *
     * @param string $personId The candidate identifier.
     *
     * @return string The absolute sub-directory path.
     */
    private function dirForPerson(string $personId): string
    {
        return sprintf('%s/%s', $this->dir, hash('sha256', $personId));
    }

    /**
     * Returns the absolute path of the JSON row for the given (candidate, raw URL) pair.
     *
     * @param string $personId    The candidate identifier.
     * @param string $obituaryUrl The source notice URL (raw, pre-normalisation).
     *
     * @return string The absolute row path.
     */
    private function pathFor(string $personId, string $obituaryUrl): string
    {
        return $this->pathForRowKey($personId, StoredMatchKey::fromUrl($obituaryUrl));
    }

    /**
     * Reads and reconstructs the stored row at the given path, or returns null when no row exists.
     *
     * @param string $path The absolute row path.
     *
     * @return StoredMatch|null The reconstructed row, or null when absent.
     */
    private function readRow(string $path): ?StoredMatch
    {
        if (!is_file($path)) {
            return null;
        }

        return StoredMatch::fromArray(AtomicFile::readJsonCapped($path, self::MAX_BYTES));
    }

    /**
     * Reconstructs every stored row across the whole store by recursing ONE level: the store root
     * holds per-candidate sub-directories ({@see dirForPerson()}), each holding that candidate's rows.
     * This is the tree-wide worklist path ({@see allPending()}), so an O(store) scan is acceptable here
     * (unlike {@see findByPerson()}, which scans a single sub-directory). Each sub-directory is scanned
     * through the same poison-tolerant, temp-file-excluding helper as findByPerson.
     *
     * @return list<StoredMatch> The stored rows, in no guaranteed order.
     */
    private function allRows(): array
    {
        // A FilesystemIterator (not glob) so a glob metacharacter (*, ?, [, ]) in the directory path
        // cannot turn the whole path into a pattern and silently mis-scan or return nothing.
        try {
            $iterator = new FilesystemIterator($this->dir, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            // The store root does not exist yet: no rows, matching the previous "no dir → no rows".
            return [];
        }

        $rows = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (!$fileInfo->isDir()) {
                // The store root holds only real per-candidate sub-directories; a stray top-level file
                // is not a row and is ignored.
                continue;
            }

            if ($fileInfo->isLink()) {
                // A symlink is skipped even when isDir() reports true THROUGH the link: this keeps the
                // read scan from following a link out of the store, mirroring the cleanup trait's
                // !isLink() guard.
                continue;
            }

            foreach ($this->scanDir($fileInfo->getPathname()) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Reconstructs every stored row in a SINGLE directory, skipping any single poison row that fails to
     * reconstruct so one corrupt file cannot hide every valid row. Shared by {@see findByPerson()} (one
     * sub-directory) and {@see allRows()} (each sub-directory in turn). The single-key read paths
     * ({@see readRow()}) deliberately stay fail-loud; only this directory scan tolerates a poison row.
     *
     * @param string $dir The absolute directory to scan for "*.json" rows.
     *
     * @return list<StoredMatch> The stored rows in that directory, in no guaranteed order.
     */
    private function scanDir(string $dir): array
    {
        // A FilesystemIterator (not glob) so a glob metacharacter (*, ?, [, ]) in the directory path
        // cannot turn the whole path into a pattern and silently mis-scan or return nothing.
        try {
            $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            // The sub-directory does not exist yet: no rows, matching the previous "no dir → no rows".
            return [];
        }

        $rows = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $path = $fileInfo->getPathname();

            // Keep only "*.json" rows: an in-flight atomic temp file is "<key>.json.tmp.<uniqid>",
            // whose extension is the uniqid (not "json"), so it is excluded exactly as glob did.
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            try {
                $rows[] = StoredMatch::fromArray(AtomicFile::readJsonCapped($path, self::MAX_BYTES));
            } catch (RuntimeException) {
                // A single malformed row is skipped, not fatal: the remaining valid rows still
                // surface. This covers the whole decode/IO corruption class — a truncated or
                // non-JSON file, a symlinked/oversize file and a wrong-shape row
                // (CorruptMatchRowException, a RuntimeException subclass) — all surface as a
                // RuntimeException (readJsonCapped converts the JSON_THROW_ON_ERROR JsonException
                // into one), without swallowing a programming error (no catch (Throwable)). A
                // single-key read of the same row stays fail-loud (see readRow).
                continue;
            }
        }

        return $rows;
    }

    /**
     * Ensures the candidate's per-person sub-directory exists, creating it on first write. Reuses
     * {@see AtomicFile::ensureDirectory()}, whose scoped error handler tolerates the mkdir race.
     *
     * @param string $personId The candidate identifier whose sub-directory to ensure.
     *
     * @return void
     */
    private function ensureLayout(string $personId): void
    {
        AtomicFile::ensureDirectory($this->dirForPerson($personId));
    }
}
