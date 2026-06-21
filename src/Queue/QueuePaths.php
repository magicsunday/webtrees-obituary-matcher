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

use function is_dir;
use function mkdir;
use function preg_match;
use function sprintf;

/**
 * Builds the on-disk paths of the file-drop queue and creates its directory layout. The queue is a
 * shared directory with four state sub-directories (queued/running/done/failed); a job moves
 * between states by an atomic rename of its file. Every method that accepts a jobId guards it
 * against path traversal so a hostile jobId can never escape the queue root.
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
     * Creates the four state directories under the queue root if they do not yet exist.
     *
     * @return void
     */
    public function ensureLayout(): void
    {
        foreach ([self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_DONE, self::STATE_FAILED] as $state) {
            $directory = $this->stateRoot($state);

            if (!is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }
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
