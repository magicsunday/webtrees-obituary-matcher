<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
use RuntimeException;

use function glob;
use function hash;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;

/**
 * A file-based {@see MatchStore}: one atomic JSON file per (candidate, normalised URL) key under a
 * single directory. The file name is a content hash of the candidate identifier and the URL identity
 * key, so two notice links pointing at the same obituary collapse onto one file and no candidate
 * identifier ever reaches the filesystem (avoiding any xref-charset escaping). The candidate
 * identifier and status are persisted inside each row, so {@see findByPerson()} and
 * {@see allPending()} scan the directory and filter on the decoded row content.
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
     * @param string $dir The directory holding one JSON row per (candidate, normalised URL) key.
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
     * @return void
     */
    public function upsertPending(StoredMatch $match): void
    {
        $path = $this->pathFor($match->personId, $match->obituaryUrl);

        $existing = $this->readRow($path);

        if (
            ($existing instanceof StoredMatch)
            && $existing->status->isTerminal()
        ) {
            return;
        }

        $this->ensureLayout();

        AtomicFile::writeJson($path, $match->toArray());
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

        foreach ($this->allRows() as $row) {
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

            if ($existing->status === MatchStatus::Rejected) {
                // Already rejected: re-rejecting is a harmless, idempotent no-op.
                return;
            }

            $match     = $existing->match;
            $writeBack = $existing->writeBack;
        } else {
            $match = [
                'personId'       => $personId,
                'obituaryUrl'    => $obituaryUrl,
                'score'          => 0,
                'hardConflict'   => false,
                'signals'        => [],
                'extractedFacts' => [],
                'classification' => '',
                'ambiguous'      => false,
                'runnerUp'       => null,
                'review'         => null,
            ];

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

        $this->ensureLayout();

        AtomicFile::writeJson($path, $rejected->toArray());
    }

    /**
     * Returns the absolute path of the JSON row for the given (candidate, normalised URL) key.
     *
     * @param string $personId    The candidate identifier.
     * @param string $obituaryUrl The source notice URL (raw, pre-normalisation).
     *
     * @return string The absolute row path.
     */
    private function pathFor(string $personId, string $obituaryUrl): string
    {
        $urlHash = hash('sha256', UrlNormalizer::normalizeForIdentity($obituaryUrl));
        $key     = hash('sha256', $personId . "\0" . $urlHash);

        return sprintf('%s/%s.json', $this->dir, $key);
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
     * Reconstructs every stored row in the directory.
     *
     * @return list<StoredMatch> The stored rows, in no guaranteed order.
     */
    private function allRows(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $paths = glob(sprintf('%s/*.json', $this->dir));

        if ($paths === false) {
            return [];
        }

        $rows = [];

        foreach ($paths as $path) {
            $rows[] = StoredMatch::fromArray(AtomicFile::readJsonCapped($path, self::MAX_BYTES));
        }

        return $rows;
    }

    /**
     * Ensures the store directory exists, creating it on first write.
     *
     * @return void
     */
    private function ensureLayout(): void
    {
        if (
            !is_dir($this->dir)
            && !mkdir($this->dir, 0o700, true)
            && !is_dir($this->dir)
        ) {
            throw new RuntimeException(
                sprintf('Failed to create match store directory: %s', $this->dir)
            );
        }
    }
}
