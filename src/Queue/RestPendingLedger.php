<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_ends_with;
use function substr;
use function unlink;

/**
 * Slim local record of in-flight REST transport jobs. The REST transport submits a job to a remote
 * feeder and has no shared queue directory to scan for outstanding work, so it remembers each submitted
 * job here as a tiny `{root}/{jobId}.json` file. The ledger is the producer side's only memory of what
 * is still pending; a consumer drains it and removes each entry once the remote result is ingested.
 *
 * Every read is poison-tolerant: a malformed, foreign or structurally invalid file is skipped, never
 * fatal, so one corrupt entry can never abort the scan. The jobId becomes a filename, so it is validated
 * against the queue's authoritative path-traversal guard ({@see QueuePaths::isJobDirectoryName()}, the
 * `^[A-Za-z0-9_-]{1,64}$` pattern) before it ever becomes a path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RestPendingLedger
{
    /**
     * @var int The maximum number of bytes a single ledger entry is read into memory. A ledger entry is
     *          a tiny object (jobId, treeId, a list of requested person xrefs, a timestamp); 64 KiB is
     *          generous headroom for thousands of xrefs while still bounding a hostile or corrupt file
     *          far below the 5 MiB feeder-file cap.
     */
    private const int ENTRY_MAX_BYTES = 65_536;

    /**
     * Constructor.
     *
     * @param string $root Absolute path to the ledger root directory (e.g. data/obituary-matcher/rest-pending).
     */
    public function __construct(
        private string $root,
    ) {
    }

    /**
     * Records an in-flight REST job as an atomic `{root}/{jobId}.json` file. The jobId is validated as a
     * safe filename BEFORE it becomes a path, so a hostile id can never escape the ledger root; an invalid
     * id is rejected (it is never written) rather than silently producing a traversal sink.
     *
     * @param string       $jobId              The job identifier, validated as a path-safe filename.
     * @param int          $treeId             The webtrees tree id the job belongs to.
     * @param list<string> $requestedPersonIds The xrefs the job requested a match for.
     * @param string       $submittedAt        The submission timestamp (an opaque ISO-8601 string).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the jobId is not a path-safe filename.
     * @throws RuntimeException         When the encoded entry exceeds the read-back byte cap (so an
     *                                  entry the scan could never read back is never written).
     */
    public function record(string $jobId, int $treeId, array $requestedPersonIds, string $submittedAt): void
    {
        if (!$this->isValidJobId($jobId)) {
            throw new InvalidArgumentException(
                sprintf('Invalid job identifier: %s', $jobId)
            );
        }

        AtomicFile::ensureDirectory($this->root);

        // Cap the write at the same byte limit entries() reads back. An entry larger than ENTRY_MAX_BYTES
        // would be written fine but then silently skipped by the capped read, orphaning the job forever;
        // rejecting it here surfaces the problem loudly at record time instead.
        AtomicFile::writeJson(
            $this->entryPath($jobId),
            [
                'jobId'              => $jobId,
                'treeId'             => $treeId,
                'requestedPersonIds' => $requestedPersonIds,
                'submittedAt'        => $submittedAt,
            ],
            self::ENTRY_MAX_BYTES
        );
    }

    /**
     * Removes a job's entry idempotently. An invalid jobId is a silent no-op (no path is ever built from
     * an unvalidated id, so removal cannot escape the root); a missing file is also a no-op. The unlink
     * runs under a scoped error handler so the "No such file" E_WARNING is not converted into a thrown
     * exception by webtrees' warning-to-exception handler — removing an unknown job must never throw.
     *
     * @param string $jobId The job identifier whose entry is removed.
     *
     * @return void
     */
    public function remove(string $jobId): void
    {
        if (!$this->isValidJobId($jobId)) {
            return;
        }

        $path = $this->entryPath($jobId);

        // Swallow the unlink warning (a missing file raises "No such file or directory") so a removal of
        // an unknown/already-removed job is a no-op rather than the warning being converted into a thrown
        // exception. This mirrors the scoped-handler guard AtomicFile already uses, without the forbidden
        // @-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Yields every well-formed pending entry. The root is scanned for `*.json` files; each is read under
     * the byte cap and narrowed to the concrete shape. ANY failure — an unreadable root, a file whose
     * basename fails the jobId guard, a non-JSON or oversized file, or a structurally invalid payload —
     * skips that entry rather than aborting the scan. A missing root yields nothing.
     *
     * @return iterable<array{jobId: string, treeId: int, requestedPersonIds: list<string>, submittedAt: string}>
     */
    public function entries(): iterable
    {
        if (!is_dir($this->root)) {
            return;
        }

        // A root that exists but is unreadable makes the FilesystemIterator constructor throw an
        // UnexpectedValueException. Mirroring QueueClient::recentJobs, the scan yields nothing rather
        // than crashing the caller.
        try {
            $iterator = new FilesystemIterator($this->root, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            $name = $entry->getFilename();

            if (!str_ends_with($name, '.json')) {
                continue;
            }

            // Skip a foreign file whose basename (minus .json) fails the same path-traversal guard a
            // recorded jobId must pass, so a poison/foreign file is never treated as an entry.
            $jobId = substr($name, 0, -5);

            if (!$this->isValidJobId($jobId)) {
                continue;
            }

            try {
                $data = AtomicFile::readJsonCapped($entry->getPathname(), self::ENTRY_MAX_BYTES);
            } catch (Throwable) {
                continue;
            }

            // The filename basename — already validated above — is the authoritative identity, so the
            // entry is narrowed against it. A payload whose own jobId field disagrees (a corrupt or
            // planted file) is rejected, guaranteeing the yielded jobId is always the path-safe basename.
            $narrowed = $this->narrow($data, $jobId);

            if ($narrowed === null) {
                continue;
            }

            yield $narrowed;
        }
    }

    /**
     * Returns the jobIds of every well-formed pending entry, derived from the same poison-tolerant scan
     * as {@see self::entries()}.
     *
     * @return list<string>
     */
    public function jobIds(): array
    {
        $ids = [];

        foreach ($this->entries() as $entry) {
            $ids[] = $entry['jobId'];
        }

        return $ids;
    }

    /**
     * Narrows an untrusted decoded entry to the concrete shape, or returns null when any of the four
     * fields is missing or of the wrong type. The person-id list is rebuilt element by element, so a
     * non-string element rejects the whole entry and the result is guaranteed to be a list of strings.
     * The payload's own jobId must equal the authoritative filename basename; a mismatch (a corrupt or
     * planted file whose content disagrees with its name) rejects the entry, so the yielded jobId is
     * always the path-safe basename rather than an unvalidated content string.
     *
     * @param array<string, mixed> $data          The untrusted decoded entry.
     * @param string               $expectedJobId The authoritative, already path-validated filename basename.
     *
     * @return array{jobId: string, treeId: int, requestedPersonIds: list<string>, submittedAt: string}|null
     */
    private function narrow(array $data, string $expectedJobId): ?array
    {
        $jobId              = $data['jobId'] ?? null;
        $treeId             = $data['treeId'] ?? null;
        $requestedPersonIds = $data['requestedPersonIds'] ?? null;
        $submittedAt        = $data['submittedAt'] ?? null;

        if (
            !is_string($jobId)
            || ($jobId !== $expectedJobId)
            || !is_int($treeId)
            || !is_string($submittedAt)
            || !is_array($requestedPersonIds)
        ) {
            return null;
        }

        $personIds = [];

        foreach ($requestedPersonIds as $personId) {
            if (!is_string($personId)) {
                return null;
            }

            $personIds[] = $personId;
        }

        return [
            'jobId'              => $jobId,
            'treeId'             => $treeId,
            'requestedPersonIds' => $personIds,
            'submittedAt'        => $submittedAt,
        ];
    }

    /**
     * Builds the absolute path of a (pre-validated) jobId's entry file.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    private function entryPath(string $jobId): string
    {
        return $this->root . '/' . $jobId . '.json';
    }

    /**
     * Reports whether a jobId is a path-safe filename by delegating to the queue's authoritative
     * path-traversal guard ({@see QueuePaths::isJobDirectoryName()}, the `^[A-Za-z0-9_-]{1,64}$`
     * pattern). The guard is a stateless predicate on the candidate name, so it is called statically —
     * reusing the single source of truth for the pattern instead of duplicating the regular expression.
     *
     * @param string $jobId The candidate job identifier.
     *
     * @return bool True when the jobId is a valid, path-safe filename.
     */
    private function isValidJobId(string $jobId): bool
    {
        return QueuePaths::isJobDirectoryName($jobId);
    }
}
