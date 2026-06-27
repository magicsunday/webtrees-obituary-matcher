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
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;

/**
 * Tests the queue path builder and layout creation, including the jobId path-traversal guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(QueuePaths::class)]
#[UsesClass(AtomicFile::class)]
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
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function jobDirectoryNames(): array
    {
        return [
            'a valid job id'              => ['job-20260623T101530Z-0a1b2c3d', true],
            'a plain alphanumeric name'   => ['job1', true],
            'the current-dir entry'       => ['.', false],
            'the parent-dir entry'        => ['..', false],
            'a reserved enqueue temp dir' => ['.tmp-abc123', false],
            'a path-traversal name'       => ['../escape', false],
            'a slash-bearing name'        => ['a/b', false],
            'an over-long name'           => ['x' . str_repeat('y', 64), false],
            'a trailing-newline name'     => ["job-1\n", false],
            'an empty name'               => ['', false],
        ];
    }

    /**
     * isJobDirectoryName accepts a real job directory name and rejects the dot pseudo-entries, the
     * reserved `.tmp-` enqueue temporary and any name failing the path-traversal guard, so a hostile
     * or foreign directory entry is skipped before any read or claim. The single predicate the
     * producer's in-flight scan and the drain's discovery both consume.
     */
    #[Test]
    #[DataProvider('jobDirectoryNames')]
    public function isJobDirectoryNameClassifiesAnEntry(string $entry, bool $expected): void
    {
        self::assertSame($expected, (new QueuePaths($this->tmp))->isJobDirectoryName($entry));
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
     * Calling ensureLayout a second time when the state directories already exist is a silent no-op:
     * an "already exists" outcome is treated as success, never raising on the concurrent-create race
     * where a competing process won the mkdir between the is_dir probe and the create.
     */
    #[Test]
    public function ensureLayoutIsIdempotentWhenDirectoriesAlreadyExist(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();

        // The directories now exist; a second call must NOT throw (the race-safe "already exists is
        // success" path), and the layout must remain intact.
        $paths->ensureLayout();

        self::assertDirectoryExists($this->tmp . '/queued');
        self::assertDirectoryExists($this->tmp . '/running');
        self::assertDirectoryExists($this->tmp . '/done');
        self::assertDirectoryExists($this->tmp . '/failed');
    }

    /**
     * A genuine creation failure (the queue root is a regular file, so no state sub-directory can be
     * created under it) surfaces as a RuntimeException rather than being swallowed silently.
     */
    #[Test]
    public function ensureLayoutThrowsWhenAStateDirectoryCannotBeCreated(): void
    {
        $rootFile = $this->tmp . '/not-a-directory';
        file_put_contents($rootFile, 'x');

        // The forced mkdir failure emits an expected warning; a scoped handler swallows it without the
        // forbidden @-suppression operator, mirroring the queue client's own failure-path tests.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to create directory/');
            (new QueuePaths($rootFile))->ensureLayout();
        } finally {
            restore_error_handler();
        }
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
            JobState::Queued->value       => $paths->queuedDir('job-1'),
            JobState::Running->value      => $paths->runningDir('job-2'),
            JobState::Done->value         => $paths->doneDir('job-3'),
            JobState::Failed->value       => $paths->failedDir('job-4'),
            JobState::Ingesting->value    => $paths->ingestingDir('job-5'),
            JobState::Ingested->value     => $paths->ingestedDir('job-6'),
            JobState::FailedIngest->value => $paths->failedIngestDir('job-7'),
        ];

        foreach ($directories as $directory) {
            mkdir($directory, 0o700, true);
        }

        self::assertSame(JobState::Queued, $paths->stateOf('job-1'));
        self::assertSame(JobState::Running, $paths->stateOf('job-2'));
        self::assertSame(JobState::Done, $paths->stateOf('job-3'));
        self::assertSame(JobState::Failed, $paths->stateOf('job-4'));
        self::assertSame(JobState::Ingesting, $paths->stateOf('job-5'));
        self::assertSame(JobState::Ingested, $paths->stateOf('job-6'));
        self::assertSame(JobState::FailedIngest, $paths->stateOf('job-7'));
    }

    /**
     * A job whose directory lives under the "ingested/" state root resolves to {@see JobState::Ingested}
     * through the module-owned {@see QueuePaths::ingestedDir()} builder, proving the new module-side
     * state directory is matched by stateOf without a near-duplicate path method.
     */
    #[Test]
    public function stateOfResolvesAnIngestedJob(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();

        mkdir($paths->ingestedDir('job-1'), 0o700, true);

        self::assertSame(JobState::Ingested, $paths->stateOf('job-1'));
    }

    /**
     * stateOf reads fresh directory state on every call: a state directory that becomes present
     * AFTER an earlier negative stat (which populated PHP's stat cache with a "does not exist"
     * result for that path) is still observed. This guards the clearstatcache() that protects the
     * cross-process rename-transition design — a stale cache entry would otherwise hide the new
     * directory.
     */
    #[Test]
    public function stateOfReadsFreshStateAfterAPathIsCachedAsAbsent(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();

        $runningDir = $paths->runningDir('job-1');

        // Prime PHP's stat cache with a negative result for the not-yet-existing directory.
        self::assertDirectoryDoesNotExist($runningDir);
        self::assertFalse(is_dir($runningDir));

        // Create the directory; without clearstatcache() the primed negative cache entry would make
        // the subsequent is_dir() inside stateOf still report the path as absent.
        mkdir($runningDir, 0o700, true);

        self::assertSame(JobState::Running, $paths->stateOf('job-1'));
    }
}
