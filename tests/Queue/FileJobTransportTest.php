<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\FileJobTransport;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function iterator_to_array;

/**
 * Drives {@see FileJobTransport} against a real on-disk file-drop queue: a claimable done job
 * surfaces as a {@see CompletedJob} carrying its decoded notices, a corrupt response surfaces as a
 * {@see FailedJob} under the preserved file-path reason category, and the in-flight request scan is
 * poison-tolerant — proving the transport encapsulates the discover + claim + read the drain used to
 * perform inline, with the failure categories the on-disk status.json persists today unchanged.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileJobTransport::class)]
#[UsesClass(QueueClient::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(FeederRequestReader::class)]
#[UsesClass(ResponseReader::class)]
#[UsesClass(ResponseValidationException::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(JobState::class)]
#[UsesClass(CompletedJob::class)]
#[UsesClass(FailedJob::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryDateParser::class)]
final class FileJobTransportTest extends QueueTempDirTestCase
{
    /**
     * A claimable done job (a valid request + a valid response) is yielded as a single
     * {@see CompletedJob} carrying the requested person ids and the decoded notices keyed by person.
     *
     * @return void
     */
    #[Test]
    public function aDoneJobIsYieldedAsACompletedJobWithNotices(): void
    {
        $this->placeRequest('job-1', 'request-valid.json', JobState::Done);
        $this->placeResponse('job-1', 'response-valid.json', JobState::Done);

        $jobs = iterator_to_array($this->newFileTransport()->fetchCompleted(), false);

        self::assertCount(1, $jobs);
        self::assertInstanceOf(CompletedJob::class, $jobs[0]);
        self::assertSame('job-1', $jobs[0]->jobId);
        self::assertSame(['I1'], $jobs[0]->requestedPersonIds);
        self::assertArrayHasKey('I1', $jobs[0]->notices);
    }

    /**
     * A schema-invalid response yields a {@see FailedJob} under the `response_invalid` category — the
     * exact reason the file path's status.json persists today, so the transport preserves it byte-for-byte.
     *
     * @return void
     */
    #[Test]
    public function aMalformedResponseYieldsAResponseInvalidFailedJob(): void
    {
        $this->placeRequest('job-1', 'request-valid.json', JobState::Done);
        $this->placeResponse('job-1', 'response-bad-schema.json', JobState::Done);

        $jobs = iterator_to_array($this->newFileTransport()->fetchCompleted(), false);

        self::assertCount(1, $jobs);
        self::assertInstanceOf(FailedJob::class, $jobs[0]);
        self::assertSame('response_invalid', $jobs[0]->reasonCategory);
    }

    /**
     * A request.json carrying a wrong/missing schemaVersion makes {@see FeederRequestReader} throw a
     * {@see ResponseValidationException}, surfacing as a {@see FailedJob} under the `schema_invalid`
     * category — the exact reason the file path's status.json persists today.
     *
     * @return void
     */
    #[Test]
    public function aSchemaInvalidRequestYieldsASchemaInvalidFailedJob(): void
    {
        // Valid JSON (so readJsonCapped() does NOT throw) but a wrong schemaVersion, so the reader's
        // version gate throws a ResponseValidationException -> schema_invalid.
        $this->placeRaw(
            'job-x',
            'request.json',
            '{"schemaVersion": 999, "jobId": "job-x", "treeId": 1, "candidates": []}',
            JobState::Done,
        );

        $jobs = iterator_to_array($this->newFileTransport()->fetchCompleted(), false);

        self::assertCount(1, $jobs);
        self::assertInstanceOf(FailedJob::class, $jobs[0]);
        self::assertSame('schema_invalid', $jobs[0]->reasonCategory);
    }

    /**
     * A torn (malformed-JSON) request.json makes {@see FeederRequestReader} throw a plain
     * {@see \RuntimeException} from the IO read, surfacing as a {@see FailedJob} under the
     * `request_failed` category — never mislabelled as a validation reject.
     *
     * @return void
     */
    #[Test]
    public function aTornRequestYieldsARequestFailedFailedJob(): void
    {
        $this->placeRaw('job-x', 'request.json', '{not json', JobState::Done);

        $jobs = iterator_to_array($this->newFileTransport()->fetchCompleted(), false);

        self::assertCount(1, $jobs);
        self::assertInstanceOf(FailedJob::class, $jobs[0]);
        self::assertSame('request_failed', $jobs[0]->reasonCategory);
    }

    /**
     * A valid request.json with a torn (malformed-JSON) response.json makes {@see ResponseReader}
     * throw a plain {@see \RuntimeException} from the IO read, surfacing as a {@see FailedJob} under the
     * `ingest_failed` category — a RESPONSE-read IO fault is never mislabelled as a request fault.
     *
     * @return void
     */
    #[Test]
    public function aTornResponseYieldsAnIngestFailedFailedJob(): void
    {
        $this->placeRequest('job-x', 'request-valid.json', JobState::Done);
        $this->placeRaw('job-x', 'response.json', '{not json', JobState::Done);

        $jobs = iterator_to_array($this->newFileTransport()->fetchCompleted(), false);

        self::assertCount(1, $jobs);
        self::assertInstanceOf(FailedJob::class, $jobs[0]);
        self::assertSame('ingest_failed', $jobs[0]->reasonCategory);
    }

    /**
     * The in-flight request scan yields the one valid request and SKIPS a job directory whose
     * request.json is malformed JSON (the reader throws), matching today's
     * {@see \MagicSunday\ObituaryMatcher\Webtrees\EnqueueService} in-flight dedup tolerance.
     *
     * @return void
     */
    #[Test]
    public function inFlightRequestsSkipsAPoisonRequestFile(): void
    {
        $this->placeRequest('job-ok', 'request-valid.json', JobState::Queued);
        $this->placeRaw('job-bad', 'request.json', '{not json', JobState::Queued);

        $requests = iterator_to_array($this->newFileTransport()->inFlightRequests(), false);

        self::assertCount(1, $requests);
        self::assertSame(['I1'], $requests[0]['requestedPersonIds']);
    }

    /**
     * Builds a {@see FileJobTransport} over this test's throwaway queue root through the same
     * collaborators the {@see \MagicSunday\ObituaryMatcher\Webtrees\DrainServiceFactory} wires.
     *
     * @return FileJobTransport
     */
    private function newFileTransport(): FileJobTransport
    {
        $paths = new QueuePaths($this->tmp);

        // The claim renames a done job into the ingesting state, so every state root must exist first
        // (the producer/CLI calls this; the bare temp-dir base does not).
        $paths->ensureLayout();

        return new FileJobTransport(
            new QueueClient($paths),
            new ResponseReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            $paths,
        );
    }
}
