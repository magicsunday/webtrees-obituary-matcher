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
