<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function mkdir;

/**
 * Tests the queue path builder and layout creation, including the jobId path-traversal guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(QueuePaths::class)]
#[UsesClass(JobState::class)]
final class QueuePathsTest extends TempDirTestCase
{
    /**
     * A jobId containing a path-traversal sequence is rejected before any path is built.
     */
    #[Test]
    public function rejectsAJobIdWithPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueuePaths($this->tmp))->queuedDir('../escape');
    }

    /**
     * ensureLayout creates the state directories and the path builder returns the expected job path.
     */
    #[Test]
    public function buildsStateDirsAndCreatesLayout(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();
        self::assertDirectoryExists($this->tmp . '/queued');
        self::assertSame($this->tmp . '/done/job-1', $paths->doneDir('job-1'));
    }

    /**
     * stateOf returns null when the job exists in none of the four state directories.
     */
    #[Test]
    public function stateOfReturnsNullForAnAbsentJob(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();

        self::assertNull($paths->stateOf('absent'));
    }

    /**
     * stateOf returns the matching JobState once the job's directory is placed in that state.
     */
    #[Test]
    public function stateOfLocatesTheJobInEachState(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();

        $directories = [
            JobState::Queued->value  => $paths->queuedDir('job-1'),
            JobState::Running->value => $paths->runningDir('job-2'),
            JobState::Done->value    => $paths->doneDir('job-3'),
            JobState::Failed->value  => $paths->failedDir('job-4'),
        ];

        foreach ($directories as $directory) {
            mkdir($directory, 0o700, true);
        }

        self::assertSame(JobState::Queued, $paths->stateOf('job-1'));
        self::assertSame(JobState::Running, $paths->stateOf('job-2'));
        self::assertSame(JobState::Done, $paths->stateOf('job-3'));
        self::assertSame(JobState::Failed, $paths->stateOf('job-4'));
    }
}
