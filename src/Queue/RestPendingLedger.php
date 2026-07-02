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
use MagicSunday\ObituaryMatcher\Support\JobId;
use RuntimeException;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

use function count;
use function fclose;
use function filemtime;
use function flock;
use function fopen;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function iterator_count;
use function iterator_to_array;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_ends_with;
use function substr;
use function time;
use function touch;
use function unlink;

use const LOCK_EX;
use const LOCK_UN;

/**
 * Slim local record of in-flight REST transport jobs. The REST transport submits a job to a remote
 * finder and has no shared queue directory to scan for outstanding work, so it remembers each submitted
 * job here as a tiny `{root}/{jobId}.json` file. The ledger is the producer side's only memory of what
 * is still pending; a consumer drains it and removes each entry once the remote result is ingested.
 *
 * Concurrent drains (a slow run plus the next scheduled tick) are serialised by an atomic CLAIM: before
 * a drain processes an entry it moves it from the pending root into the `{root}/claimed/` subdirectory
 * with a single-winner {@see rename()} (the loser observes the source gone and skips), so a given job is
 * processed by at most one drain. The claim is held across the whole poll+ingest of that one job and
 * dropped on finalisation ({@see remove()}) or handed back ({@see release()}) when the job is not yet
 * ready. A claim stranded by a crashed drain (older than {@see STALE_SECONDS}) is swept back to pending
 * so it is retried, and is surfaced by {@see staleCount()}. A claimed-but-unfinalised job is still in
 * flight, so {@see entries()}/{@see jobIds()}/{@see openJobCount()} report the union of both locations.
 *
 * The mutating transitions (claim, release, remove and the stale reclaim) are serialised by an exclusive
 * advisory lock ({@see LOCK_FILE}, held only for the microseconds a rename takes, never across a network
 * poll or an ingest), so a check-then-rename in one drain is never split by a competing transition in
 * another — this is what makes the claim atomic against a concurrent reclaim and the finalisation atomic
 * against a concurrent release. The READ scans ({@see entries()}/{@see openJobCount()}/{@see jobIds()})
 * deliberately run lock-free to keep the enqueue-dedup and control-panel paths cheap; a job caught
 * mid-rename by a concurrent union scan could momentarily be missed, at worst enqueuing a redundant
 * (idempotently-ingested) duplicate job — a bounded, non-corrupting best-effort trade.
 *
 * The delivery guarantee is EFFECTIVELY-ONCE in normal operation and at-least-once under a crash: the
 * lock gives true at-most-once for two healthy overlapping drains, but the stale-reclaim is a LEASE, not a
 * proof the owner died — a drain that crashes mid-ingest (or, pathologically, holds a single job's claim
 * past the one-hour lease) can have its job reclaimed and re-ingested by a later drain. This is a
 * deliberate, bounded trade — reclaim keeps a crashed job from stranding forever — and is NON-CORRUPTING
 * because the ingest sink is idempotent (`MatchStore::upsertPending` writes per-rowKey via an atomic
 * rename, last-writer-wins, and guards terminal rows), so a duplicate ingest converges to the same state.
 * The lease horizon is far beyond any real per-job ingest (sub-second), so the reclaim only fires after a
 * genuine crash. Fencing tokens (to make a stale owner's late finalisation a no-op) are intentionally NOT
 * used: they buy nothing over the idempotent sink for this single-writer, cron-driven deployment.
 *
 * Every read is poison-tolerant: a malformed, foreign or structurally invalid file is skipped, never
 * fatal, so one corrupt entry can never abort the scan. The jobId becomes a filename, so it is validated
 * against the path-safety guard ({@see JobId::isSafeForStorage()}, the
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
     *          far below the 5 MiB finder-file cap.
     */
    private const int ENTRY_MAX_BYTES = 65_536;

    /**
     * @var string The subdirectory (under the ledger root) that holds CLAIMED entries. A claim moves an
     *             entry from `{root}/{jobId}.json` to `{root}/claimed/{jobId}.json`; the two locations live
     *             on the same filesystem, so the move is a single atomic rename.
     */
    private const string CLAIMED_DIRECTORY = 'claimed';

    /**
     * @var int The age (in seconds) past which a CLAIMED entry is assumed stranded by a crashed drain and
     *          is swept back to pending for retry (and counted by {@see staleCount()}). A claim is normally
     *          held only for the seconds a single job's poll+ingest takes; one hour is far beyond any
     *          legitimate hold while still bounding how long a crashed claim blocks its job.
     */
    private const int STALE_SECONDS = 3_600;

    /**
     * @var string The advisory lock file (under the ledger root) that serialises the mutating state
     *             transitions — claim, release, remove and the stale reclaim — across concurrent drains, so
     *             a check-then-rename is never split by a competing transition. It is NOT a `*.json` file,
     *             so the entry scans skip it.
     */
    private const string LOCK_FILE = '.lock';

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
     * Atomically claims a pending entry for the calling drain by moving `{root}/{jobId}.json` into
     * `{root}/claimed/{jobId}.json`. Exactly one caller can win the move — the {@see rename()} is atomic,
     * so a concurrent drain observes the source already gone and its own claim returns false.
     *
     * The whole operation runs under the ledger's advisory write lock, so the rename and the mtime stamp
     * are atomic with respect to a concurrent {@see reclaimStale()} — a sweep can never observe the entry
     * between the rename and the touch and mistake a live claim for a stale one. The rename is the
     * single-winner gate: it must run FIRST and against the existing pending source, so only the drain
     * whose rename finds the source present wins. The claim time is then stamped onto the CLAIMED file (the
     * rename preserves the entry's original record mtime, which for a long-queued job can be hours old, so
     * without the touch it would look stale the instant it is claimed). Stamping cannot precede the rename:
     * touch() would CREATE the missing source, letting a losing drain resurrect a ghost pending entry whose
     * rename then overwrites the winner's claimed file. Both operations run under a scoped error handler so
     * a failure (source gone because another drain won, permission denied) returns false rather than being
     * converted into a thrown exception by webtrees' warning-to-exception handler.
     *
     * @param string $jobId The pending job to claim (validated as a path-safe filename).
     *
     * @return bool True when this caller won the claim; false when it lost the race, the entry vanished,
     *              or the jobId is not a path-safe filename.
     */
    public function claim(string $jobId): bool
    {
        if (!$this->isValidJobId($jobId)) {
            return false;
        }

        return $this->withLock(function () use ($jobId): bool {
            AtomicFile::ensureDirectory($this->claimedRoot());

            set_error_handler(static fn (): bool => true);

            try {
                // Rename FIRST — the single-winner gate. Only the drain whose rename finds the pending
                // source present wins; the loser's rename fails (source already gone) and returns false.
                // The lock held around this whole block makes the rename-then-touch atomic with respect to
                // a concurrent reclaim, so no sweep can observe the post-rename-pre-touch state.
                $claimed = rename($this->entryPath($jobId), $this->claimedPath($jobId));

                if ($claimed) {
                    // Stamp the claim time on the won claim so staleness is measured from the claim, not the
                    // entry's (possibly hours-old) original record time. Touching the claimed file is safe:
                    // it exists (the rename just succeeded) and is owned by this drain, so it cannot
                    // resurrect a pending ghost the way touching the source would.
                    touch($this->claimedPath($jobId));
                }
            } finally {
                restore_error_handler();
            }

            return $claimed;
        });
    }

    /**
     * Yields every claimable entry, already CLAIMED for the calling drain. First any claim stranded by a
     * crashed drain (older than {@see STALE_SECONDS}) is swept back to pending so it is retried; then each
     * pending entry is claimed and, on a won claim, yielded. An entry another drain claims first is simply
     * skipped. Exactly one entry is claimed per yield (there is no look-ahead), so a caller that stops
     * mid-iteration — a drain breaking at its per-run cap — never over-claims a job it will not process.
     *
     * @return iterable<array{jobId: string, treeId: int, requestedPersonIds: list<string>, submittedAt: string}>
     */
    public function claimable(): iterable
    {
        // Reclaim crash-stranded claims first: a claim held past STALE_SECONDS is assumed abandoned and
        // moved back to pending so the loop below can re-claim it. The stale-check and the moves run under
        // one lock hold so they are atomic with respect to a concurrent claim.
        $this->reclaimStale();

        // Snapshot the pending FILENAMES before claiming — a claim renames the file out of the pending
        // root, and mutating a directory a FilesystemIterator is walking is undefined. Only the cheap
        // basenames are materialised here; the entry body is read and narrowed lazily per iteration below,
        // so a backlog of N pending entries drained at a per-run cap of L costs N readdir entries plus a
        // read only for the (≈L) entries actually processed, not N up-front JSON decodes.
        foreach (iterator_to_array($this->iterateEntryFiles($this->root), false) as $file) {
            // Read and narrow the PENDING file before claiming, so a poison entry is skipped WITHOUT being
            // claimed (matching the entries() scan) and the read is lazy — the generator stops at the
            // consumer's cap rather than front-loading the whole backlog.
            try {
                $data = AtomicFile::readJsonCapped($file['pathname'], self::ENTRY_MAX_BYTES);
            } catch (Throwable) {
                continue;
            }

            $narrowed = $this->narrow($data, $file['jobId']);

            if ($narrowed === null) {
                continue;
            }

            if ($this->claim($file['jobId'])) {
                yield $narrowed;
            }
        }
    }

    /**
     * Releases a claimed entry back to the pending pool by moving `{root}/claimed/{jobId}.json` to
     * `{root}/{jobId}.json`, so a later drain re-polls it. Used when a drain observes a claimed job is not
     * yet ready (a transient transport fault or a still-running remote job) and when a crashed claim is
     * swept back for retry. Idempotent: an unknown/already-released job (the source is gone) is a no-op,
     * as is an invalid jobId. The rename runs under a scoped error handler so a missing source does not
     * surface as a thrown warning.
     *
     * @param string $jobId The claimed job to release back to pending.
     *
     * @return void
     */
    public function release(string $jobId): void
    {
        if (!$this->isValidJobId($jobId)) {
            return;
        }

        $this->withLock(function () use ($jobId): void {
            set_error_handler(static fn (): bool => true);

            try {
                rename($this->claimedPath($jobId), $this->entryPath($jobId));
            } finally {
                restore_error_handler();
            }
        });
    }

    /**
     * Removes a job's entry idempotently from BOTH the pending root and the claimed subdirectory, so a
     * finalised job is cleared wherever it currently sits (a drained job is claimed, but a never-claimed
     * one may still be pending). An invalid jobId is a silent no-op (no path is ever built from an
     * unvalidated id, so removal cannot escape the root); a missing file is also a no-op. Each unlink runs
     * under a scoped error handler so the "No such file" E_WARNING is not converted into a thrown exception
     * by webtrees' warning-to-exception handler — removing an unknown job must never throw.
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

        // A finalised job may be pending (never claimed) or claimed (drained): clear both locations under
        // the write lock so the two unlinks are atomic with respect to a concurrent reclaim moving the
        // entry between them.
        $this->withLock(function () use ($jobId): void {
            $this->unlinkSilently($this->entryPath($jobId));
            $this->unlinkSilently($this->claimedPath($jobId));
        });
    }

    /**
     * Yields every well-formed in-flight entry — the union of the pending root and the claimed
     * subdirectory, because a claimed-but-unfinalised job is still in flight (the enqueue producer must
     * still dedupe against it). Each location is scanned for `*.json` files; each is read under the byte
     * cap and narrowed to the concrete shape. ANY failure — an unreadable root, a file whose basename
     * fails the jobId guard, a non-JSON or oversized file, or a structurally invalid payload — skips that
     * entry rather than aborting the scan. A missing location yields nothing.
     *
     * @return iterable<array{jobId: string, treeId: int, requestedPersonIds: list<string>, submittedAt: string}>
     */
    public function entries(): iterable
    {
        yield from $this->scanDirectory($this->root);
        yield from $this->scanDirectory($this->claimedRoot());
    }

    /**
     * Counts every in-flight ledger FILE whose basename is a path-safe jobId, across BOTH the pending root
     * and the claimed subdirectory, regardless of whether its CONTENT narrows cleanly. A poisoned,
     * oversized or structurally-invalid entry still represents a remote job that was submitted and not yet
     * drained, so it counts as open here (in contrast to {@see self::entries()}/{@see self::jobIds()},
     * which skip such files). A claimed job is still open, so the claimed subdirectory is counted too.
     * Returns 0 when the ledger root does not exist or cannot be read. Mirrors the {@see self::entries()}
     * scan (FilesystemIterator + isFile + `.json` suffix, UnexpectedValueException-guarded) so the
     * `claimed/` DIRECTORY under the root is NOT miscounted and an unreadable root cannot break the
     * control-panel render.
     *
     * @return int The number of open finder jobs in the ledger.
     */
    public function openJobCount(): int
    {
        return $this->countDirectory($this->root) + $this->countDirectory($this->claimedRoot());
    }

    /**
     * Returns the jobIds of every well-formed in-flight entry, derived from the same poison-tolerant
     * union scan as {@see self::entries()}.
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
     * The number of claims stranded mid-ingest by a crashed drain: claimed entries whose stamp is older
     * than {@see STALE_SECONDS}. Fresh claims (a drain actively processing a job) are excluded, so this
     * counts only work a crash left blocked — the drain summary's stale tally. A claim this old is also
     * swept back to pending by the next {@see claimable()} pass, so the count is self-healing.
     *
     * @return int The stale (crash-stranded) claim count.
     */
    public function staleCount(): int
    {
        return count($this->staleClaimedJobIds());
    }

    /**
     * Sweeps every crash-stranded claim (a claimed entry older than {@see STALE_SECONDS}) back to the
     * pending pool so a later claim re-processes it. The stale-check and every move run under ONE hold of
     * the write lock, so the decision and the rename are atomic with respect to a concurrent claim — a
     * freshly-claimed entry can never be reclaimed between another drain re-stamping it and this sweep
     * moving it. Each rename runs under a scoped error handler so a vanished source (already released or
     * finalised) is a no-op rather than a thrown warning.
     *
     * @return void
     */
    private function reclaimStale(): void
    {
        $this->withLock(function (): void {
            foreach ($this->staleClaimedJobIds() as $jobId) {
                set_error_handler(static fn (): bool => true);

                try {
                    rename($this->claimedPath($jobId), $this->entryPath($jobId));
                } finally {
                    restore_error_handler();
                }
            }
        });
    }

    /**
     * Returns the jobIds of every CLAIMED entry whose stamp is older than {@see STALE_SECONDS} — a claim a
     * crashed drain never finalised or released. The claimed subdirectory is scanned with the same
     * FilesystemIterator + isFile + `.json` + path-safe-basename discipline as the other scans; a file
     * whose mtime cannot be read (it vanished mid-scan) is skipped. A missing claimed subdirectory yields
     * an empty list.
     *
     * @return list<string> The stale claimed jobIds.
     */
    private function staleClaimedJobIds(): array
    {
        $threshold = time() - self::STALE_SECONDS;
        $stale     = [];

        foreach ($this->iterateEntryFiles($this->claimedRoot()) as $file) {
            $mtime = $this->fileMTime($file['pathname']);

            if (
                ($mtime !== null)
                && ($mtime <= $threshold)
            ) {
                $stale[] = $file['jobId'];
            }
        }

        return $stale;
    }

    /**
     * Scans a single ledger directory (the pending root or the claimed subdirectory) and yields every
     * well-formed entry it holds, applying the poison-tolerant read-narrow discipline. Shared by
     * {@see self::entries()} (both locations) and {@see self::claimable()} (the pending root only).
     *
     * @param string $directory The absolute directory to scan.
     *
     * @return iterable<array{jobId: string, treeId: int, requestedPersonIds: list<string>, submittedAt: string}>
     */
    private function scanDirectory(string $directory): iterable
    {
        foreach ($this->iterateEntryFiles($directory) as $file) {
            try {
                $data = AtomicFile::readJsonCapped($file['pathname'], self::ENTRY_MAX_BYTES);
            } catch (Throwable) {
                continue;
            }

            // The filename basename — already validated by iterateEntryFiles — is the authoritative
            // identity, so the entry is narrowed against it. A payload whose own jobId field disagrees (a
            // corrupt or planted file) is rejected, guaranteeing the yielded jobId is always the path-safe
            // basename.
            $narrowed = $this->narrow($data, $file['jobId']);

            if ($narrowed === null) {
                continue;
            }

            yield $narrowed;
        }
    }

    /**
     * Counts every path-safe `*.json` FILE in a single ledger directory, regardless of whether its content
     * narrows cleanly. A path-safe `*.json` DIRECTORY (or the `claimed/` subdirectory under the root) is
     * skipped by the isFile guard, and an unsafe basename by the path-safety guard. Returns 0 when the
     * directory does not exist or cannot be read.
     *
     * @param string $directory The absolute directory to count.
     *
     * @return int The number of path-safe ledger files in the directory.
     */
    private function countDirectory(string $directory): int
    {
        // iterator_count consumes the generator without materialising it, so counting a large ledger stays
        // O(1) in memory (a plain running counter) rather than building and discarding an N-element array.
        return iterator_count($this->iterateEntryFiles($directory));
    }

    /**
     * Yields the identity of every path-safe `*.json` ledger FILE in a directory — a `{jobId, pathname}`
     * pair — without reading the file body. This is the single source of truth for the directory-walk
     * discipline shared by the read scan ({@see scanDirectory()}), the count ({@see countDirectory()}), the
     * stale sweep ({@see staleClaimedJobIds()}) and the claim loop ({@see claimable()}): a
     * FilesystemIterator (SKIP_DOTS, UnexpectedValueException-guarded so an unreadable/absent directory
     * yields nothing), skipping non-files (the `claimed/` subdirectory and any `*.json` directory), files
     * not ending `.json`, and basenames that fail the path-safety guard. Emitting the bare identity lets
     * each consumer decide whether to read the body, so the claim loop can defer the read until after it
     * wins the claim.
     *
     * @param string $directory The absolute directory to walk.
     *
     * @return iterable<array{jobId: string, pathname: string}> The path-safe ledger files in the directory.
     */
    private function iterateEntryFiles(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            // Skips the `claimed/` subdirectory when walking the pending root (a directory is not a file).
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

            yield [
                'jobId'    => $jobId,
                'pathname' => $entry->getPathname(),
            ];
        }
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
     * Runs a mutating ledger transition under an exclusive advisory lock so concurrent drains serialise on
     * it: a check-then-rename (claim, release, remove, reclaim) is never split by a competing transition,
     * which is what closes the claim/reclaim and finalise/reclaim races between two overlapping drains. The
     * lock is scoped to the individual transition only — it is NEVER held across a network poll or an
     * ingest — so drains contend only for the microseconds a rename takes. If the lock file cannot be
     * opened (a permission fault), the operation runs UNLOCKED as a best-effort fallback rather than
     * failing the drain: the idempotent match store remains the correctness backstop. The lock handle is
     * always unlocked and closed, even when the operation throws.
     *
     * @template T
     *
     * @param callable(): T $operation The transition to run while holding the lock.
     *
     * @return T The operation's return value.
     */
    private function withLock(callable $operation): mixed
    {
        AtomicFile::ensureDirectory($this->root);

        // Open (create if absent, never truncate) the lock file under a scoped handler so a fopen warning
        // is not converted into a thrown exception by webtrees' warning-to-exception handler.
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($this->lockPath(), 'c');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            // Best-effort: run unlocked rather than failing the drain; the idempotent store is the backstop.
            return $operation();
        }

        try {
            flock($handle, LOCK_EX);

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Unlinks a path idempotently under a scoped error handler, so a missing file (the "No such file"
     * E_WARNING) is a no-op rather than a thrown exception. Mirrors the scoped-handler guard AtomicFile
     * uses, without the forbidden @-suppression operator.
     *
     * @param string $path The absolute path to remove.
     *
     * @return void
     */
    private function unlinkSilently(string $path): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Reads a file's modification time under a scoped error handler, returning null when it cannot be read
     * (the file vanished mid-scan), so a concurrent removal never surfaces as a thrown warning.
     *
     * @param string $path The absolute path whose mtime is read.
     *
     * @return int|null The Unix mtime, or null when it cannot be determined.
     */
    private function fileMTime(string $path): ?int
    {
        set_error_handler(static fn (): bool => true);

        try {
            $mtime = filemtime($path);
        } finally {
            restore_error_handler();
        }

        return $mtime === false ? null : $mtime;
    }

    /**
     * Builds the absolute path of a (pre-validated) jobId's PENDING entry file.
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
     * Builds the absolute path of a (pre-validated) jobId's CLAIMED entry file.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    private function claimedPath(string $jobId): string
    {
        return $this->claimedRoot() . '/' . $jobId . '.json';
    }

    /**
     * Builds the absolute path of the claimed-entries subdirectory under the ledger root.
     *
     * @return string
     */
    private function claimedRoot(): string
    {
        return $this->root . '/' . self::CLAIMED_DIRECTORY;
    }

    /**
     * Builds the absolute path of the advisory lock file under the ledger root.
     *
     * @return string
     */
    private function lockPath(): string
    {
        return $this->root . '/' . self::LOCK_FILE;
    }

    /**
     * Reports whether a jobId is a path-safe filename by delegating to the path-safety guard
     * ({@see JobId::isSafeForStorage()}, the `^[A-Za-z0-9_-]{1,64}$` pattern). The guard is a stateless
     * predicate on the candidate name, so it is called statically — reusing the single source of truth
     * for the pattern instead of duplicating the regular expression.
     *
     * @param string $jobId The candidate job identifier.
     *
     * @return bool True when the jobId is a valid, path-safe filename.
     */
    private function isValidJobId(string $jobId): bool
    {
        return JobId::isSafeForStorage($jobId);
    }
}
