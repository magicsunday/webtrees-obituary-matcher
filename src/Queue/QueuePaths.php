<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use InvalidArgumentException;
use RuntimeException;

use function clearstatcache;
use function is_dir;
use function preg_match;
use function sprintf;

/**
 * Builds the on-disk paths of the file-drop queue and creates its directory layout. The queue is a
 * shared directory with seven state sub-directories: the worker-side queued/running/done/failed and
 * the module-side ingesting/ingested/failed-ingest. A job moves between states by an atomic rename of
 * its directory. Every method that accepts a jobId guards it against path traversal so a hostile
 * jobId can never escape the queue root.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class QueuePaths
{
    /**
     * @var string The "queued" state directory name.
     */
    private const string STATE_QUEUED = 'queued';

    /**
     * @var string The "running" state directory name.
     */
    private const string STATE_RUNNING = 'running';

    /**
     * @var string The "done" state directory name.
     */
    private const string STATE_DONE = 'done';

    /**
     * @var string The "failed" state directory name.
     */
    private const string STATE_FAILED = 'failed';

    /**
     * @var string The "ingesting" state directory name (a module-claimed done job).
     */
    private const string STATE_INGESTING = 'ingesting';

    /**
     * @var string The "ingested" state directory name (the module finished ingesting the response).
     */
    private const string STATE_INGESTED = 'ingested';

    /**
     * @var string The "failed-ingest" state directory name (the module failed to ingest the response).
     */
    private const string STATE_FAILED_INGEST = 'failed-ingest';

    /**
     * @var string Regular expression a jobId must match to be accepted (path-traversal guard).
     */
    private const string JOB_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param string $root Absolute path to the queue root directory.
     */
    public function __construct(
        private string $root,
    ) {
    }

    /**
     * Returns the absolute path to a job's file in the "queued" state directory.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    public function queuedDir(string $jobId): string
    {
        return $this->stateRoot(self::STATE_QUEUED) . '/' . $this->validateJobId($jobId);
    }

    /**
     * Returns the absolute path to a job's file in the "running" state directory.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    public function runningDir(string $jobId): string
    {
        return $this->stateRoot(self::STATE_RUNNING) . '/' . $this->validateJobId($jobId);
    }

    /**
     * Returns the absolute path to a job's file in the "done" state directory.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    public function doneDir(string $jobId): string
    {
        return $this->stateRoot(self::STATE_DONE) . '/' . $this->validateJobId($jobId);
    }

    /**
     * Returns the absolute path to a job's file in the "failed" state directory.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     */
    public function failedDir(string $jobId): string
    {
        return $this->stateRoot(self::STATE_FAILED) . '/' . $this->validateJobId($jobId);
    }

    /**
     * Returns the absolute path to a job's directory in the "ingesting" state (a module-claimed done
     * job). A thin wrapper over {@see self::stateDir()} so the module-side states carry the same
     * validateJobId-guarded, named builders as the worker-side states.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    public function ingestingDir(string $jobId): string
    {
        return $this->stateDir(JobState::Ingesting, $jobId);
    }

    /**
     * Returns the absolute path to a job's directory in the "ingested" state (the module finished
     * ingesting the response). A thin wrapper over {@see self::stateDir()}.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    public function ingestedDir(string $jobId): string
    {
        return $this->stateDir(JobState::Ingested, $jobId);
    }

    /**
     * Returns the absolute path to a job's directory in the "failed-ingest" state (the module failed
     * to ingest the response). A thin wrapper over {@see self::stateDir()}.
     *
     * @param string $jobId The validated job identifier.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    public function failedIngestDir(string $jobId): string
    {
        return $this->stateDir(JobState::FailedIngest, $jobId);
    }

    /**
     * Returns the absolute path to a job's directory under the given state. The jobId is validated
     * against the path-traversal guard, so a hostile jobId can never escape the queue root.
     *
     * @param JobState $state The state whose directory holds the job.
     * @param string   $jobId The validated job identifier.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    public function stateDir(JobState $state, string $jobId): string
    {
        return $this->stateRoot($state->value) . '/' . $this->validateJobId($jobId);
    }

    /**
     * Returns the absolute path to a state's directory under the queue root.
     *
     * @param string $state The state directory name.
     *
     * @return string
     */
    public function stateRoot(string $state): string
    {
        return $this->root . '/' . $state;
    }

    /**
     * Locates which state a job currently lives in by probing each state directory in the
     * authoritative {@see JobState::cases()} order (queued → running → done → failed → ingesting →
     * ingested → failed-ingest). Returns the first matching state, or null when the job exists in no
     * state. The jobId is validated once up front, so a hostile jobId can never escape the queue root.
     *
     * @param string $jobId The job identifier to locate.
     *
     * @return JobState|null The state whose directory exists for the job, or null when none does.
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    public function stateOf(string $jobId): ?JobState
    {
        $this->validateJobId($jobId);

        // The queue transitions jobs by RENAMING their directory between states across processes (a
        // feeder renames running/<job> → done/<job> while this module process reads state). PHP only
        // auto-clears its stat/realpath cache for the process that performed a rename, never for a
        // different process that merely stat'd the path earlier in the same request. Clearing the cache
        // here forces each is_dir() below to read fresh directory state, so a second stateOf() call in
        // the same request cannot observe a stale state after a concurrent rename.
        clearstatcache(true);

        foreach (JobState::cases() as $state) {
            if (is_dir($this->stateRoot($state->value) . '/' . $jobId)) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Creates the seven state directories under the queue root if they do not yet exist. The create is
     * race-safe: a concurrent process winning the mkdir between the is_dir probe and the create leaves
     * the directory present, which counts as success; only a genuine failure (the directory still does
     * not exist afterwards) raises.
     *
     * @return void
     *
     * @throws RuntimeException When a state directory cannot be created.
     */
    public function ensureLayout(): void
    {
        $states = [
            self::STATE_QUEUED,
            self::STATE_RUNNING,
            self::STATE_DONE,
            self::STATE_FAILED,
            self::STATE_INGESTING,
            self::STATE_INGESTED,
            self::STATE_FAILED_INGEST,
        ];

        foreach ($states as $state) {
            AtomicFile::ensureDirectory($this->stateRoot($state));
        }
    }

    /**
     * Validates a jobId against the path-traversal guard and returns it unchanged.
     *
     * @param string $jobId The job identifier to validate.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the jobId does not match the allowed pattern.
     */
    private function validateJobId(string $jobId): string
    {
        if (preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid job identifier: %s', $jobId)
            );
        }

        return $jobId;
    }
}
