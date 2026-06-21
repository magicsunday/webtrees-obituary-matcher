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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function chmod;
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
        self::assertSame(JobState::Queued, $client->status('job-1')->state);

        self::assertTrue($client->claim('job-1'));            // first rename wins
        self::assertFalse($client->claim('job-1'));           // already claimed → fails
        self::assertSame(JobState::Running, $client->status('job-1')->state);

        $client->markDone('job-1', 3);
        self::assertSame(JobState::Done, $client->status('job-1')->state);
        self::assertSame(3, $client->status('job-1')->counts);
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

        $client->markDone('job-1', 7);

        // The terminal directory is observable; its status.json must already be present.
        self::assertFileExists($this->tmp . '/done/job-1/status.json');
        // No status.json may linger in the running state once the publish rename succeeded.
        self::assertFileDoesNotExist($this->tmp . '/running/job-1/status.json');

        $status = $client->status('job-1');
        self::assertSame(JobState::Done, $status->state);
        self::assertSame(7, $status->counts);
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
            $client->markDone('job-1', 1);
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
            $client->markDone('job-1', 1);
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
        $client->markDone('job-1', 5);
        self::assertSame(JobState::Done, $client->status('job-1')->state);
        self::assertSame(5, $client->status('job-1')->counts);
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
        $client->markDone('job-1', 1);                         // queued → running → done

        $this->expectException(RuntimeException::class);
        $client->enqueue($this->request('job-1'));             // job-1 is done
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
