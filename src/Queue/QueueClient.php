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
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

use function array_keys;
use function array_slice;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function max;
use function mkdir;
use function rename;
use function restore_error_handler;
use function rmdir;
use function rsort;
use function set_error_handler;
use function sprintf;
use function uniqid;
use function unlink;

use const SORT_STRING;

/**
 * Drives the file-drop queue state machine on top of {@see QueuePaths} and {@see AtomicFile}. A job
 * lives as a directory that moves queued → running → done|failed by atomic rename. Job-directory
 * creation is itself atomic (populated in a temporary directory, then renamed into place) so a
 * worker never observes a half-written job. This class is pure: it has no webtrees coupling and
 * never reads the wall clock, so transitions stay deterministic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class QueueClient
{
    /**
     * @var string The status file written by the terminal transitions, relative to the job dir.
     */
    private const string STATUS_FILE = '/status.json';

    /**
     * @var string The request payload file written into a job's queued directory.
     */
    private const string REQUEST_FILE = '/request.json';

    /**
     * @var int The maximum accepted size of a status.json file in bytes.
     */
    private const int STATUS_MAX_BYTES = 65536;

    /**
     * @var string Reserved name prefix for an in-flight job's temporary directory. A future scan of
     *             the queued state must exclude any directory carrying this prefix.
     */
    private const string TEMP_DIR_PREFIX = '.tmp-';

    /**
     * Constructor.
     *
     * @param QueuePaths $paths The path builder for the queue this client operates on.
     */
    public function __construct(
        private QueuePaths $paths,
    ) {
    }

    /**
     * Atomically enqueues a feeder request as a new job directory. The job is fully populated in a
     * temporary directory (a reserved {@see self::TEMP_DIR_PREFIX} name that any future queued-dir
     * scan must exclude) and only then renamed into the queued state, so a worker never observes a
     * half-written job. Refuses to clobber a jobId that already exists in ANY of the four states
     * (queued/running/done/failed): a duplicate would otherwise strand in queued (a claim onto an
     * existing running dir returns false) or make markDone/markFailed throw (a rename onto an
     * existing terminal dir fails). jobIds are therefore assumed globally unique per request.
     *
     * @param FeederRequest $request The request to enqueue; its jobId becomes the job directory name.
     *
     * @return string The enqueued job's identifier.
     *
     * @throws RuntimeException When the job already exists, the temporary directory cannot be created
     *                          or the rename into the queued state fails.
     */
    public function enqueue(FeederRequest $request): string
    {
        $this->paths->ensureLayout();

        $payload   = $request->toArray();
        $jobId     = $payload['jobId'];
        $targetDir = $this->paths->queuedDir($jobId);

        // The guard covers every state, not just queued: a jobId already in running/done/failed
        // would otherwise create a duplicate that strands or makes a later terminal rename throw.
        if ($this->paths->stateOf($jobId) instanceof JobState) {
            throw new RuntimeException(
                sprintf('Refusing to clobber an existing job: %s', $jobId)
            );
        }

        $tempDir = $this->paths->stateRoot('queued')
            . '/' . self::TEMP_DIR_PREFIX . $jobId . '-' . uniqid('', true);

        if (!mkdir($tempDir, 0o700, true)) {
            throw new RuntimeException(
                sprintf('Failed to create temporary job directory: %s', $tempDir)
            );
        }

        // Wrap the write AND the publish rename so the populated temp dir is ALWAYS removed on any
        // failure. A custom error handler (webtrees installs one) can convert a writeJson or rename
        // E_WARNING into a thrown exception that bypasses the "if (!rename(...))" branch and would
        // otherwise leak the populated .tmp- directory; catching every Throwable and removing the
        // temp dir in the catch closes that leak too.
        try {
            AtomicFile::writeJson($tempDir . self::REQUEST_FILE, $payload);

            if (!rename($tempDir, $targetDir)) {
                throw new RuntimeException(
                    sprintf('Failed to atomically move job %s into the queued state', $jobId)
                );
            }
        } catch (Throwable $exception) {
            // Remove the fully populated temp dir so a failed enqueue does not leak an orphan .tmp-
            // directory in the queued state. The cleanup is best-effort: removeDirectory can itself
            // throw (a filesystem warning the webtrees error handler converts into an exception, or a
            // RecursiveDirectoryIterator on a vanished entry), and that must never mask the original
            // failure — so it is swallowed and the original $exception is always the one re-thrown.
            try {
                $this->removeDirectory($tempDir);
            } catch (Throwable) {
                // Best-effort cleanup: never let a cleanup failure mask the original error.
            }

            throw $exception;
        }

        return $jobId;
    }

    /**
     * Atomically claims a queued job by renaming it into the running state. The rename is the
     * synchronisation point: at most one caller can win it, so a losing or missing claim simply
     * returns false. The expected race outcome never surfaces as a warning.
     *
     * @param string $jobId The job identifier to claim.
     *
     * @return bool True when this caller won the claim, false when the job is missing or already claimed.
     */
    public function claim(string $jobId): bool
    {
        $queuedDir = $this->paths->queuedDir($jobId);

        if (!is_dir($queuedDir)) {
            return false;
        }

        // The rename is the synchronisation point: a losing or racing claim is an expected outcome,
        // not a warning. A scoped handler swallows the rename's warning (the boolean return already
        // carries the lost/racing-claim signal) without the forbidden @-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            return rename($queuedDir, $this->paths->runningDir($jobId));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Marks a running job as done. Mirroring {@see enqueue}, the status file is written into the
     * running directory FIRST and a single atomic rename then publishes a done directory that
     * already contains its status.json, so a reader detecting the done state by directory existence
     * can never observe it without the status file (no half-published window). No wall-clock
     * timestamp is fabricated here; finishedAt is left null because the deterministic transition
     * carries none. On a failed rename the status file is left in the running directory, which is
     * harmless: it never escapes running/ and is overwritten on the next attempt.
     *
     * The counts/warnings shape mirrors the Python worker's status.json exactly: `counts` is a
     * per-metric map (candidates/queries/notices/skippedNotices/portalErrors) and `warnings` a list
     * of strings, so {@see status()} round-trips whichever producer wrote the file.
     *
     * @param string             $jobId    The job identifier to complete.
     * @param array<string, int> $counts   The per-metric production counts to record.
     * @param list<string>       $warnings The non-fatal warnings to record (empty when there are none).
     *
     * @return void
     *
     * @throws RuntimeException When the running-to-done rename fails.
     */
    public function markDone(string $jobId, array $counts, array $warnings = []): void
    {
        $runningDir = $this->paths->runningDir($jobId);

        AtomicFile::writeJson(
            $runningDir . self::STATUS_FILE,
            [
                'state'      => JobState::Done->value,
                'finishedAt' => null,
                'counts'     => $counts,
                'warnings'   => $warnings,
            ]
        );

        if (!rename($runningDir, $this->paths->doneDir($jobId))) {
            throw new RuntimeException(
                sprintf('Failed to move job %s into the done state', $jobId)
            );
        }
    }

    /**
     * Marks a running job as failed. Mirroring {@see enqueue}, the status file carrying the failure
     * message is written into the running directory FIRST and a single atomic rename then publishes
     * a failed directory that already contains its status.json, so a reader detecting the failed
     * state by directory existence can never observe it without the status file (no half-published
     * window). On a failed rename the status file is left in the running directory, which is
     * harmless: it never escapes running/ and is overwritten on the next attempt.
     *
     * @param string $jobId The job identifier to fail.
     * @param string $error The failure message to record.
     *
     * @return void
     *
     * @throws RuntimeException When the running-to-failed rename fails.
     */
    public function markFailed(string $jobId, string $error): void
    {
        $runningDir = $this->paths->runningDir($jobId);

        AtomicFile::writeJson(
            $runningDir . self::STATUS_FILE,
            [
                'state' => JobState::Failed->value,
                'error' => $error,
            ]
        );

        if (!rename($runningDir, $this->paths->failedDir($jobId))) {
            throw new RuntimeException(
                sprintf('Failed to move job %s into the failed state', $jobId)
            );
        }
    }

    /**
     * Atomically claims a done job for ingest by renaming it into the ingesting state. The rename is
     * the synchronisation point: at most one caller can win it, so a losing or missing claim simply
     * returns false. Mirrors {@see claim()} exactly (the queued → running claim), so the expected race
     * outcome never surfaces as a warning.
     *
     * @param string $jobId The job identifier to claim for ingest.
     *
     * @return bool True when this caller won the claim, false when the job is missing or already claimed.
     */
    public function claimForIngest(string $jobId): bool
    {
        $doneDir = $this->paths->doneDir($jobId);

        if (!is_dir($doneDir)) {
            return false;
        }

        // The rename is the synchronisation point: a losing or racing claim is an expected outcome,
        // not a warning. A scoped handler swallows the rename's warning (the boolean return already
        // carries the lost/racing-claim signal) without the forbidden @-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            return rename($doneDir, $this->paths->ingestingDir($jobId));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Marks an ingesting job as ingested. Mirroring {@see markDone}, the status file is written into
     * the ingesting directory FIRST and a single atomic rename then publishes an ingested directory
     * that already contains its status.json, so a reader detecting the ingested state by directory
     * existence can never observe it without the status file (no half-published window). On a failed
     * rename the status file is left in the ingesting directory, which is harmless: it never escapes
     * ingesting/ and is overwritten on the next attempt.
     *
     * @param string             $jobId    The job identifier to complete.
     * @param array<string, int> $counts   The per-metric ingest counts to record
     *                                     (noticesRead, candidatesFound, matchesStored).
     * @param list<string>       $warnings The non-fatal warnings to record (empty when there are none).
     *
     * @return void
     *
     * @throws RuntimeException When the ingesting-to-ingested rename fails.
     */
    public function markIngested(string $jobId, array $counts, array $warnings = []): void
    {
        $ingestingDir = $this->paths->ingestingDir($jobId);

        AtomicFile::writeJson(
            $ingestingDir . self::STATUS_FILE,
            [
                'state'      => JobState::Ingested->value,
                'finishedAt' => null,
                'counts'     => $counts,
                'warnings'   => $warnings,
            ]
        );

        if (!rename($ingestingDir, $this->paths->ingestedDir($jobId))) {
            throw new RuntimeException(
                sprintf('Failed to move job %s into the ingested state', $jobId)
            );
        }
    }

    /**
     * Marks an ingesting job as failed-ingest. Mirroring {@see markFailed}, the status file carrying
     * the failure reason category is written into the ingesting directory FIRST and a single atomic
     * rename then publishes a failed-ingest directory that already contains its status.json, so a
     * reader detecting the failed-ingest state by directory existence can never observe it without the
     * status file (no half-published window). On a failed rename the status file is left in the
     * ingesting directory, which is harmless: it never escapes ingesting/ and is overwritten on the
     * next attempt.
     *
     * @param string       $jobId          The job identifier to fail.
     * @param string       $reasonCategory The failure reason category to record.
     * @param list<string> $warnings       The non-fatal warnings to record (empty when there are none).
     *
     * @return void
     *
     * @throws RuntimeException When the ingesting-to-failed-ingest rename fails.
     */
    public function markFailedIngest(string $jobId, string $reasonCategory, array $warnings = []): void
    {
        $ingestingDir = $this->paths->ingestingDir($jobId);

        AtomicFile::writeJson(
            $ingestingDir . self::STATUS_FILE,
            [
                'state'    => JobState::FailedIngest->value,
                'reason'   => $reasonCategory,
                'warnings' => $warnings,
            ]
        );

        if (!rename($ingestingDir, $this->paths->failedIngestDir($jobId))) {
            throw new RuntimeException(
                sprintf('Failed to move job %s into the failed-ingest state', $jobId)
            );
        }
    }

    /**
     * Atomically releases an ingesting job back to the done state by renaming its directory, so a
     * crashed or retrying ingest can re-claim it. The rename is the synchronisation point: at most one
     * caller can win it, so a losing or missing release simply returns false. Mirrors {@see claim()},
     * so the expected race outcome never surfaces as a warning.
     *
     * @param string $jobId The job identifier to release back to the done state.
     *
     * @return bool True when this caller won the release, false when the job is missing or already released.
     */
    public function releaseIngesting(string $jobId): bool
    {
        $ingestingDir = $this->paths->ingestingDir($jobId);

        if (!is_dir($ingestingDir)) {
            return false;
        }

        // The rename is the synchronisation point: a losing or racing release is an expected outcome,
        // not a warning. A scoped handler swallows the rename's warning (the boolean return already
        // carries the lost/racing-release signal) without the forbidden @-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            return rename($ingestingDir, $this->paths->doneDir($jobId));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Returns the current status of a job by locating its directory across the seven states. The
     * terminal states (done/failed/ingested/failed-ingest) read their persisted status.json and
     * narrow each decoded key before constructing the value object; the transient states
     * (queued/running/ingesting) are synthesised from the directory location alone.
     *
     * @param string $jobId The job identifier to look up.
     *
     * @return JobStatus
     *
     * @throws RuntimeException When the job exists in none of the seven states.
     */
    public function status(string $jobId): JobStatus
    {
        $state = $this->paths->stateOf($jobId);

        return match ($state) {
            null              => throw new RuntimeException(sprintf('Unknown job: %s', $jobId)),
            JobState::Queued  => new JobStatus(JobState::Queued, null, null, null, [], []),
            JobState::Running => new JobStatus(JobState::Running, null, null, null, [], []),
            JobState::Done    => $this->readStatus(
                JobState::Done,
                $this->paths->doneDir($jobId) . self::STATUS_FILE
            ),
            JobState::Failed => $this->readStatus(
                JobState::Failed,
                $this->paths->failedDir($jobId) . self::STATUS_FILE
            ),
            JobState::Ingesting => new JobStatus(JobState::Ingesting, null, null, null, [], []),
            JobState::Ingested  => $this->readStatus(
                JobState::Ingested,
                $this->paths->ingestedDir($jobId) . self::STATUS_FILE
            ),
            JobState::FailedIngest => $this->readStatus(
                JobState::FailedIngest,
                $this->paths->failedIngestDir($jobId) . self::STATUS_FILE
            ),
        };
    }

    /**
     * Returns the most-recent jobs across ALL state directories, keyed by job id, most-recent-first.
     * The id is sorted descending as a string (chronological for the module-minted `job-<ts>-<hex>`
     * ids); the list is capped to $limit BEFORE hydration, then each id's status is read. A job whose
     * status cannot be read is skipped (poison-tolerant), so fewer than $limit rows may return.
     *
     * @param int $limit The maximum number of jobs to return.
     *
     * @return array<string, JobStatus> The jobId => JobStatus map, most-recent-first.
     */
    public function recentJobs(int $limit): array
    {
        $seen = [];

        foreach (JobState::cases() as $state) {
            $root = $this->paths->stateRoot($state->value);

            if (!is_dir($root)) {
                continue;
            }

            foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (
                    ($entry instanceof SplFileInfo)
                    && $entry->isDir()
                    && $this->paths->isJobDirectoryName($entry->getFilename())
                ) {
                    // Dedupe: a job id should live in exactly one state dir, but a torn move could
                    // surface the same id in two — hydrate it once.
                    $seen[$entry->getFilename()] = true;
                }
            }
        }

        $ids = array_keys($seen);
        rsort($ids, SORT_STRING);
        $ids = array_slice($ids, 0, max(0, $limit));

        $jobs = [];

        foreach ($ids as $jobId) {
            try {
                $jobs[$jobId] = $this->status($jobId);
            } catch (RuntimeException) {
                // A job whose status is unreadable/corrupt is skipped, not fatal.
                continue;
            }
        }

        return $jobs;
    }

    /**
     * Reads a terminal job's status.json and builds a {@see JobStatus}, narrowing each decoded key
     * so no untyped value reaches the value object.
     *
     * @param JobState $state The terminal state the job directory was found in.
     * @param string   $path  The absolute path to the job's status.json.
     *
     * @return JobStatus
     *
     * @throws RuntimeException When the status file cannot be read or decoded.
     */
    private function readStatus(JobState $state, string $path): JobStatus
    {
        $data = AtomicFile::readJsonCapped($path, self::STATUS_MAX_BYTES);

        // startedAt has no producer in this feeder-minimal slice: it is written by the worker on
        // claim (a deliberate forward seam), so reading it here is intentional, not dead plumbing.
        $startedAt  = ($data['startedAt'] ?? null);
        $finishedAt = ($data['finishedAt'] ?? null);

        // A failed-ingest status.json records its failure under 'reason' (a reason CATEGORY, mirroring
        // the worker's 'error' message field): surface it through the same JobStatus::error slot, so a
        // failed worker scrape and a failed module ingest read out identically. The worker-side 'error'
        // key still wins when both are present, keeping every existing failed-status read unchanged.
        $error = ($data['error'] ?? ($data['reason'] ?? null));

        return new JobStatus(
            $state,
            is_string($startedAt) ? $startedAt : null,
            is_string($finishedAt) ? $finishedAt : null,
            is_string($error) ? $error : null,
            $this->narrowCounts($data['counts'] ?? null),
            $this->narrowWarnings($data['warnings'] ?? null),
        );
    }

    /**
     * Narrows a decoded `counts` value into a string-keyed int map, dropping any entry whose key is
     * not a string or whose value is not an int, so no untyped data reaches the value object.
     *
     * @param mixed $counts The raw decoded counts value.
     *
     * @return array<string, int>
     */
    private function narrowCounts(mixed $counts): array
    {
        if (!is_array($counts)) {
            return [];
        }

        $narrowed = [];

        foreach ($counts as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $narrowed[$key] = $value;
            }
        }

        return $narrowed;
    }

    /**
     * Narrows a decoded `warnings` value into a list of strings, dropping any non-string entry, so no
     * untyped data reaches the value object.
     *
     * @param mixed $warnings The raw decoded warnings value.
     *
     * @return list<string>
     */
    private function narrowWarnings(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            return [];
        }

        $narrowed = [];

        foreach ($warnings as $value) {
            if (is_string($value)) {
                $narrowed[] = $value;
            }
        }

        return $narrowed;
    }

    /**
     * Recursively removes a populated directory and everything below it. Used only to clean up a
     * partially-built temporary job directory after a failed enqueue rename, so a failure does not
     * leak an orphan directory.
     *
     * This is a best-effort cleanup invoked from a failure handler that already swallows its own
     * throw, so a single entry's deletion must never abort the rest. A scoped error handler swallows
     * each unlink/rmdir warning (mirroring {@see self::claim()} and {@see AtomicFile::ensureDirectory()})
     * so that under a custom error handler that converts an E_WARNING into a thrown ErrorException
     * (webtrees installs one) one entry's failure does not abort the loop and strand the rest: the
     * boolean returns carry the per-entry outcome and the loop continues regardless, removing as much
     * of the tree as it can.
     *
     * @param string $directory The absolute path to remove.
     *
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        // Swallow each unlink/rmdir warning so a single entry's deletion failure (a converted
        // E_WARNING) does not abort the loop and strand the remaining entries. The boolean returns are
        // intentionally ignored: this is a best-effort cleanup whose caller already swallows its throw.
        set_error_handler(static fn (): bool => true);

        try {
            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }

            rmdir($directory);
        } finally {
            restore_error_handler();
        }
    }
}
