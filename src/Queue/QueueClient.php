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

use function is_dir;
use function is_int;
use function is_string;
use function mkdir;
use function rename;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function sprintf;
use function uniqid;
use function unlink;

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
     * @param string   $jobId  The job identifier to complete.
     * @param int|null $counts The number of produced results, or null if unknown.
     *
     * @return void
     *
     * @throws RuntimeException When the running-to-done rename fails.
     */
    public function markDone(string $jobId, ?int $counts): void
    {
        $runningDir = $this->paths->runningDir($jobId);

        AtomicFile::writeJson(
            $runningDir . self::STATUS_FILE,
            [
                'state'      => JobState::Done->value,
                'finishedAt' => null,
                'counts'     => $counts,
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
     * Returns the current status of a job by locating its directory across the four states. The
     * terminal states (done/failed) read their persisted status.json and narrow each decoded key
     * before constructing the value object; the transient states (queued/running) are synthesised
     * from the directory location alone.
     *
     * @param string $jobId The job identifier to look up.
     *
     * @return JobStatus
     *
     * @throws RuntimeException When the job exists in none of the four states.
     */
    public function status(string $jobId): JobStatus
    {
        $state = $this->paths->stateOf($jobId);

        return match ($state) {
            null              => throw new RuntimeException(sprintf('Unknown job: %s', $jobId)),
            JobState::Queued  => new JobStatus(JobState::Queued, null, null, null, null),
            JobState::Running => new JobStatus(JobState::Running, null, null, null, null),
            JobState::Done    => $this->readStatus(
                JobState::Done,
                $this->paths->doneDir($jobId) . self::STATUS_FILE
            ),
            JobState::Failed => $this->readStatus(
                JobState::Failed,
                $this->paths->failedDir($jobId) . self::STATUS_FILE
            ),
        };
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
        $error      = ($data['error'] ?? null);
        $counts     = ($data['counts'] ?? null);

        return new JobStatus(
            $state,
            is_string($startedAt) ? $startedAt : null,
            is_string($finishedAt) ? $finishedAt : null,
            is_string($error) ? $error : null,
            is_int($counts) ? $counts : null,
        );
    }

    /**
     * Recursively removes a populated directory and everything below it. Used only to clean up a
     * partially-built temporary job directory after a failed enqueue rename, so a failure does not
     * leak an orphan directory.
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

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
