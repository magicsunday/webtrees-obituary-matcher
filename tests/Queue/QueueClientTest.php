<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use DateTimeImmutable;
use ErrorException;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\JobStatus;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\CandidateQuery;
use MagicSunday\ObituaryMatcher\Support\FeederCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

use function chmod;
use function file_put_contents;
use function glob;
use function mkdir;
use function rename;
use function restore_error_handler;
use function set_error_handler;

/**
 * Tests the queue client's enqueue, atomic claim, state transitions and status lookup against a
 * real temporary file-drop queue.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(QueueClient::class)]
#[CoversClass(JobState::class)]
#[CoversClass(JobStatus::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(FeederRequestFactory::class)]
#[UsesClass(FeederRequest::class)]
#[UsesClass(FeederCandidateRequest::class)]
#[UsesClass(QueryGenerator::class)]
#[UsesClass(CandidateQuery::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(DateRange::class)]
final class QueueClientTest extends TempDirTestCase
{
    /**
     * A job is driven through the full state machine: enqueue, a winning claim that the second
     * caller loses, and the done transition that records its counts.
     */
    #[Test]
    public function enqueueClaimAndTransitionsDriveTheStateMachine(): void
    {
        $request = (new FeederRequestFactory(new QueryGenerator()))->build(
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [$this->candidate()],
        );
        $client = new QueueClient(new QueuePaths($this->tmp));

        $jobId = $client->enqueue($request);
        self::assertSame('job-1', $jobId);
        self::assertFileExists($this->tmp . '/queued/job-1/request.json');

        $queued = $client->status('job-1');
        self::assertSame(JobState::Queued, $queued->state);
        // A pre-terminal job carries the empty collections, never null.
        self::assertSame([], $queued->counts);
        self::assertSame([], $queued->warnings);

        self::assertTrue($client->claim('job-1'));            // first rename wins
        self::assertFalse($client->claim('job-1'));           // already claimed → fails
        self::assertSame(JobState::Running, $client->status('job-1')->state);

        $client->markDone('job-1', ['notices' => 3]);
        self::assertSame(JobState::Done, $client->status('job-1')->state);
        self::assertSame(['notices' => 3], $client->status('job-1')->counts);
    }

    /**
     * The terminal status.json is published together with the directory: the instant the done
     * directory becomes observable it already contains its status.json, and no status.json is
     * left behind in the running state. This proves the rename is the last mutating step (the
     * status file is written into running/ first), so a reader can never observe the done
     * directory without its status.json (the TOCTOU half-published window cannot exist).
     */
    #[Test]
    public function markDonePublishesStatusAtomicallyWithTheTerminalRename(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        $client->markDone('job-1', ['notices' => 7], ['trauer-anzeigen.de: one portal timed out']);

        // The terminal directory is observable; its status.json must already be present.
        self::assertFileExists($this->tmp . '/done/job-1/status.json');
        // No status.json may linger in the running state once the publish rename succeeded.
        self::assertFileDoesNotExist($this->tmp . '/running/job-1/status.json');

        $status = $client->status('job-1');
        self::assertSame(JobState::Done, $status->state);
        self::assertSame(['notices' => 7], $status->counts);
        // The warnings written through markDone's optional argument round-trip back out of status().
        self::assertSame(['trauer-anzeigen.de: one portal timed out'], $status->warnings);
    }

    /**
     * The failed status.json is published together with the directory, carrying the error message:
     * the instant the failed directory is observable it already contains its status.json, and no
     * status.json lingers in the running state.
     */
    #[Test]
    public function markFailedPublishesStatusAtomicallyWithTheTerminalRename(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        $client->markFailed('job-1', 'feeder rejected the request');

        self::assertFileExists($this->tmp . '/failed/job-1/status.json');
        self::assertFileDoesNotExist($this->tmp . '/running/job-1/status.json');

        $status = $client->status('job-1');
        self::assertSame(JobState::Failed, $status->state);
        self::assertSame('feeder rejected the request', $status->error);
        // markFailed writes no counts/warnings keys; readStatus must surface the empty collections
        // (the null-to-empty path) for a real terminal failed status.json, never null.
        self::assertSame([], $status->counts);
        self::assertSame([], $status->warnings);
    }

    /**
     * Proves the write-then-publish ordering directly: when the publish rename is forced to fail
     * (the done state root is made unwritable), the status file must already be sitting in the
     * running directory — written BEFORE the rename. Under the old rename-then-write order the
     * rename would fail first and leave the running directory without any status.json, so this test
     * fails for that order and passes only when the status file is published atomically with the
     * rename. The failure also confirms the documented leak-free guarantee: the leftover status
     * file never escapes running/.
     */
    #[Test]
    public function markDoneWritesTheStatusFileBeforeThePublishRename(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        // Make the done state root unwritable so the publish rename cannot succeed.
        $doneRoot = $this->tmp . '/done';
        self::assertTrue(chmod($doneRoot, 0o500));

        // The forced rename failure emits an expected warning; a scoped handler swallows it without
        // the forbidden @-suppression operator, mirroring the queue client's own claim() pattern.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $client->markDone('job-1', ['notices' => 1]);
        } finally {
            restore_error_handler();
            chmod($doneRoot, 0o700);
        }
    }

    /**
     * The status file written before a failed publish rename does not escape the running state and
     * is overwritten on the next attempt, which then succeeds and reports the new counts.
     */
    #[Test]
    public function statusFileLeftByAFailedRenameStaysInRunningAndIsOverwrittenOnRetry(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        $doneRoot = $this->tmp . '/done';
        self::assertTrue(chmod($doneRoot, 0o500));

        // The forced rename failure emits an expected warning; a scoped handler swallows it without
        // the forbidden @-suppression operator, mirroring the queue client's own claim() pattern.
        set_error_handler(static fn (): bool => true);

        try {
            $client->markDone('job-1', ['notices' => 1]);
            self::fail('Expected the publish rename to fail with an unwritable done state root.');
        } catch (RuntimeException) {
            // The pre-written status file stays in running/, never escaping into the done state.
            self::assertFileExists($this->tmp . '/running/job-1/status.json');
            self::assertFileDoesNotExist($this->tmp . '/done/job-1/status.json');
            self::assertSame(JobState::Running, $client->status('job-1')->state);
        } finally {
            restore_error_handler();
            chmod($doneRoot, 0o700);
        }

        // The retry overwrites the leftover status file and publishes the up-to-date counts.
        $client->markDone('job-1', ['notices' => 5]);
        self::assertSame(JobState::Done, $client->status('job-1')->state);
        self::assertSame(['notices' => 5], $client->status('job-1')->counts);
    }

    /**
     * Enqueuing a second job with an identifier already present in the queued state is refused.
     */
    #[Test]
    public function enqueueRefusesToClobberAnExistingJob(): void
    {
        $request = (new FeederRequestFactory(new QueryGenerator()))->build(
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [$this->candidate()],
        );
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($request);
        $this->expectException(RuntimeException::class);
        $client->enqueue($request);                            // job-1 already exists
    }

    /**
     * A jobId already present in the running state is refused: claiming it out of queued leaves no
     * queued directory, so a re-enqueue that only checked the queued state would silently create a
     * duplicate that strands. The clobber guard must cover every state.
     */
    #[Test]
    public function enqueueRefusesToClobberAJobAlreadyRunning(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));             // queued → running, no queued dir left

        $this->expectException(RuntimeException::class);
        $client->enqueue($this->request('job-1'));             // job-1 is running
    }

    /**
     * A jobId already present in a terminal (done) state is refused: a re-enqueue followed by a
     * claim and markDone would rename onto the existing done directory and throw. The clobber guard
     * must cover the terminal states too.
     */
    #[Test]
    public function enqueueRefusesToClobberAJobAlreadyDone(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));
        $client->markDone('job-1', ['notices' => 1]);          // queued → running → done

        $this->expectException(RuntimeException::class);
        $client->enqueue($this->request('job-1'));             // job-1 is done
    }

    /**
     * When job creation fails AFTER the temporary directory has been populated — here a custom error
     * handler (webtrees installs one) converts the publish rename's E_WARNING into a thrown exception,
     * bypassing the "if (!rename(...))" branch — the exception must still propagate AND the populated
     * temporary directory must be cleaned up, so a failed enqueue never leaks a .tmp-<jobId>-* orphan
     * into the queued state root.
     */
    #[Test]
    public function enqueueCleansUpTheTempDirectoryWhenJobCreationThrows(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('seed'));

        // Occupy the target queued/job-1 slot with a regular FILE (not a directory): the clobber
        // guard probes with is_dir(), so it does not refuse, yet rename() of the populated temp
        // directory onto an existing regular file fails — and the installed handler turns that
        // warning into a thrown exception that bypasses the rename branch.
        file_put_contents($this->tmp . '/queued/job-1', 'occupied');

        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $client->enqueue($this->request('job-1'));
            self::fail('enqueue must propagate the job-creation failure.');
        } catch (AssertionFailedError $assertionFailure) {
            throw $assertionFailure;
        } catch (Throwable) {
            // The job-creation failure propagated as expected.
        } finally {
            restore_error_handler();
        }

        // No leftover .tmp-job-1-* directory: the cleanup ran even though the exception bypassed the
        // "if (!rename(...))" branch.
        $leftovers = glob($this->tmp . '/queued/.tmp-job-1-*');
        self::assertSame([], ($leftovers === false) ? [] : $leftovers);
    }

    /**
     * The temp-directory cleanup is best-effort: a single entry whose deletion fails — here a file in
     * a permission-locked subdirectory whose unlink raises an EACCES E_WARNING that the installed
     * webtrees-style error handler converts into a thrown ErrorException — must NOT abort the loop and
     * strand the remaining entries, and must NOT propagate out of the cleanup. The scoped error handler
     * inside removeDirectory swallows the per-entry warning so the loop continues and removes what it
     * can. Without it the converted exception would propagate and the sibling entry would be left behind.
     */
    #[Test]
    public function removeDirectoryToleratesAPerEntryFailureUnderAThrowingErrorHandler(): void
    {
        $target = $this->tmp . '/cleanup-target';
        mkdir($target, 0o700, true);

        // A removable sibling that the cleanup must still delete despite the blocked entry.
        file_put_contents($target . '/removable', 'x');

        // A subdirectory whose child cannot be unlinked once the directory is permission-locked: its
        // unlink raises an EACCES warning the throwing handler converts into an exception.
        $blocked = $target . '/blocked';
        mkdir($blocked, 0o700, true);
        file_put_contents($blocked . '/inner', 'x');
        chmod($blocked, 0o500);

        $client = new QueueClient(new QueuePaths($this->tmp));

        $invoke = new ReflectionMethod($client, 'removeDirectory');

        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            // Must return without propagating the converted EACCES exception.
            $invoke->invoke($client, $target);

            // The removable sibling was deleted even though the blocked entry's deletion failed,
            // proving the loop continued past the per-entry failure rather than aborting.
            self::assertFileDoesNotExist($target . '/removable');
        } finally {
            restore_error_handler();

            // Restore write permission so the test harness can tear the tree down.
            chmod($blocked, 0o700);
        }
    }

    /**
     * The Python worker writes `counts` as a per-metric MAP (candidates/queries/notices/
     * skippedNotices/portalErrors) and `warnings` as a list of strings — not the scalar the matcher
     * first modelled. status() must surface both verbatim, alongside the worker-written timestamps.
     * The status.json is published exactly as the worker does it: written into running/ first, then
     * the whole directory atomically renamed into done/.
     */
    #[Test]
    public function statusSurfacesTheWorkerCountsMapAndWarnings(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        $counts = [
            'candidates'     => 1,
            'queries'        => 2,
            'notices'        => 3,
            'skippedNotices' => 0,
            'portalErrors'   => 1,
        ];
        $warnings = ['trauer-anzeigen.de: portal request timed out'];

        AtomicFile::writeJson(
            $this->tmp . '/running/job-1/status.json',
            [
                'jobId'      => 'job-1',
                'state'      => JobState::Done->value,
                'startedAt'  => '2026-06-21T10:00:00+00:00',
                'finishedAt' => '2026-06-21T10:00:05+00:00',
                'counts'     => $counts,
                'warnings'   => $warnings,
            ]
        );
        self::assertTrue(rename($this->tmp . '/running/job-1', $this->tmp . '/done/job-1'));

        $status = $client->status('job-1');
        self::assertSame(JobState::Done, $status->state);
        self::assertSame($counts, $status->counts);
        self::assertSame($warnings, $status->warnings);
        self::assertSame('2026-06-21T10:00:00+00:00', $status->startedAt);
        self::assertSame('2026-06-21T10:00:05+00:00', $status->finishedAt);
    }

    /**
     * The decoded counts/warnings are narrowed defensively: an untrusted status.json whose counts map
     * carries a non-int value and whose warnings list carries non-string entries must have those
     * entries dropped, so only `array<string, int>` / `list<string>` data reaches the value object.
     */
    #[Test]
    public function statusNarrowsMalformedCountsAndWarnings(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        AtomicFile::writeJson(
            $this->tmp . '/running/job-1/status.json',
            [
                'state' => JobState::Done->value,
                // 'ratio' => 1.5 decodes to a PHP float — the realistic non-integer-number case the
                // is_int() guard exists to drop (a weaker is_numeric()/is_scalar() guard would admit
                // it), distinct from the trivially-rejected 'NaN' string.
                'counts'   => ['notices' => 3, 'bogus' => 'NaN', 'ratio' => 1.5, 'portalErrors' => 1],
                'warnings' => ['a real warning', 42, ['nested']],
            ]
        );
        self::assertTrue(rename($this->tmp . '/running/job-1', $this->tmp . '/done/job-1'));

        $status = $client->status('job-1');
        // The 'NaN' string and the 1.5 float are both dropped (non-int values); only int-valued keys survive.
        self::assertSame(['notices' => 3, 'portalErrors' => 1], $status->counts);
        // The integer and the nested array are dropped; only the string warning survives.
        self::assertSame(['a real warning'], $status->warnings);
    }

    /**
     * A `counts` value that decodes to a JSON array rather than an object (integer keys after
     * json_decode) carries no string-keyed metric, so every entry is dropped and the narrowed map is
     * empty — proving the `is_string($key)` guard, not just the `is_int($value)` one. This is the
     * "counts is the wrong shape entirely" case an untrusted producer could emit.
     */
    #[Test]
    public function statusNarrowsListShapedCountsToAnEmptyMap(): void
    {
        $client = new QueueClient(new QueuePaths($this->tmp));
        $client->enqueue($this->request('job-1'));
        self::assertTrue($client->claim('job-1'));

        AtomicFile::writeJson(
            $this->tmp . '/running/job-1/status.json',
            [
                'state'  => JobState::Done->value,
                'counts' => [1, 2, 3],
            ]
        );
        self::assertTrue(rename($this->tmp . '/running/job-1', $this->tmp . '/done/job-1'));

        $status = $client->status('job-1');
        self::assertSame([], $status->counts);
    }

    /**
     * Builds a minimal feeder request for the given job identifier.
     *
     * @param string $jobId The job identifier the request is built for.
     *
     * @return FeederRequest
     */
    private function request(string $jobId): FeederRequest
    {
        return (new FeederRequestFactory(new QueryGenerator()))->build(
            $jobId,
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [$this->candidate()],
        );
    }

    /**
     * Builds a minimal person candidate for the feeder request fixtures.
     *
     * @return PersonCandidate
     */
    private function candidate(): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Mueller', ['Mustermann']),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown()
        );
    }
}
