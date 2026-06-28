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
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
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
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Queue\RestJobTransport;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\FeederCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function array_fill;
use function array_shift;
use function iterator_to_array;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the REST job transport against a scriptable PSR-18 client double: the queued->running->done
 * poll lifecycle, the 404/5xx/connect-error branches, the malformed-done -> response_invalid mapping,
 * the bearer-header-without-token-leak contract, the release no-op, idempotent finalisation even when
 * the remote DELETE fails, and the Variante-B parity that the shared validator ignores a top-level
 * `state` field.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RestJobTransport::class)]
#[UsesClass(RestPendingLedger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(CompletedJob::class)]
#[UsesClass(FailedJob::class)]
#[UsesClass(ResponseValidator::class)]
#[UsesClass(ResponseValidationException::class)]
#[UsesClass(FinderConnection::class)]
#[UsesClass(FeederRequest::class)]
#[UsesClass(FeederCandidateRequest::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryDateParser::class)]
final class RestJobTransportTest extends TempDirTestCase
{
    /**
     * A job submitted and then polled queued -> running -> done surfaces exactly one CompletedJob once
     * the remote reports `done`, and nothing while it is still running.
     *
     * @return void
     */
    #[Test]
    public function aQueuedThenRunningThenDoneJobRoundTrips(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'queued']),
            static fn (): ResponseInterface => self::json(200, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'running']),
            fn (): ResponseInterface => self::json(200, $this->doneBody('job-1', 'I1')),
        ]);

        $transport = $this->newRest($http, $ledger);
        $transport->submit($this->feederRequest('job-1', 7, ['I1']));

        $running = iterator_to_array($transport->fetchCompleted());

        self::assertCount(0, $running);

        $done = iterator_to_array($transport->fetchCompleted());

        self::assertCount(1, $done);
        self::assertInstanceOf(CompletedJob::class, $done[0]);
        self::assertSame('job-1', $done[0]->jobId);
        self::assertSame(7, $done[0]->treeId);
        self::assertArrayHasKey('I1', $done[0]->notices);
    }

    /**
     * A 200 `done` body that fails response validation yields one FailedJob categorised
     * `response_invalid`, never a CompletedJob.
     *
     * @return void
     */
    #[Test]
    public function aMalformedDoneBodyYieldsResponseInvalid(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([
            static fn (): ResponseInterface => self::json(200, [
                'schemaVersion' => 1,
                'jobId'         => 'job-1',
                'state'         => 'done',
                'results'       => 'not-an-object',
            ]),
        ]);

        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
    }

    /**
     * A 404 means the remote forgot the job: it yields one FailedJob categorised `finder_job_missing`.
     *
     * @return void
     */
    #[Test]
    public function aFinder404YieldsFinderJobMissing(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http     = $this->http([static fn (): ResponseInterface => self::json(404, ['error' => 'gone'])]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('finder_job_missing', $outcomes[0]->reasonCategory);
    }

    /**
     * A `failed` state yields one FailedJob categorised `finder_failed`.
     *
     * @return void
     */
    #[Test]
    public function aFailedStateYieldsFinderFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([
            static fn (): ResponseInterface => self::json(200, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'failed']),
        ]);

        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('finder_failed', $outcomes[0]->reasonCategory);
    }

    /**
     * A transient 5xx leaves the job in flight: nothing is yielded and the ledger entry is kept for the
     * next drain to retry.
     *
     * @return void
     */
    #[Test]
    public function aTransient5xxLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http      = $this->http([static fn (): ResponseInterface => self::json(500, ['error' => 'busy'])]);
        $transport = $this->newRest($http, $ledger);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A connect error (the PSR-18 client throws a ClientExceptionInterface) is transient: nothing is
     * yielded and the ledger entry is kept.
     *
     * @return void
     */
    #[Test]
    public function aConnectErrorLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([
            static function (): ResponseInterface {
                throw new class('connect timed out') extends RuntimeException implements ClientExceptionInterface {};
            },
        ]);
        $transport = $this->newRest($http, $ledger);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A 200 whose body is not valid JSON is not a terminal signal: it is skipped and the ledger entry
     * is kept for the next drain.
     *
     * @return void
     */
    #[Test]
    public function anUndecodableBodyLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http      = $this->http([static fn (): ResponseInterface => self::raw(200, '{not json')]);
        $transport = $this->newRest($http, $ledger);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A 200 body larger than the byte cap is skipped (not read into memory), leaving the job in flight.
     *
     * @return void
     */
    #[Test]
    public function anOversizedBodyLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        // A 50-byte cap against a normal done body (well over 50 bytes) forces the oversize skip path.
        $http      = $this->http([fn (): ResponseInterface => self::json(200, $this->doneBody('job-1', 'I1'))]);
        $transport = $this->newRest($http, $ledger, 50);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A 200 whose `state` is not a string is skipped (it carries no usable lifecycle signal), leaving
     * the job in flight.
     *
     * @return void
     */
    #[Test]
    public function aNonStringStateLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http      = $this->http([static fn (): ResponseInterface => self::json(200, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 42])]);
        $transport = $this->newRest($http, $ledger);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A connection dropped MID-BODY (the PSR-7 stream throws on read, as a lazily-streaming client does)
     * is isolated to ITS OWN entry: that job is skipped and kept in the ledger, while a SECOND pending
     * job in the same drain still completes — the fault does not abort the loop. The response is routed
     * by request URL so the assertion holds regardless of the unordered ledger scan.
     *
     * @return void
     */
    #[Test]
    public function aMidBodyReadFaultIsIsolatedToItsOwnEntry(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-torn', 7, ['I1'], '2024-05-21T08:29:55Z');
        $ledger->record('job-ok', 7, ['I1'], '2024-05-21T08:29:55Z');

        $route = fn (RequestInterface $request): ResponseInterface => str_contains((string) $request->getUri(), 'job-torn')
            ? new Response(200, [], $this->throwingStream())
            : self::json(200, $this->doneBody('job-ok', 'I1'));

        $transport = $this->newRest($this->http([$route, $route]), $ledger);
        $outcomes  = iterator_to_array($transport->fetchCompleted());

        // The torn job yields nothing but the other pending job still completes, proving the loop was not
        // aborted; the torn entry stays in the ledger for a later retry.
        self::assertCount(1, $outcomes);
        self::assertInstanceOf(CompletedJob::class, $outcomes[0]);
        self::assertSame('job-ok', $outcomes[0]->jobId);
        self::assertContains('job-torn', $ledger->jobIds());
    }

    /**
     * A submission to an unreachable finder fails with a base-URL-only RuntimeException (no token in the
     * message or trace) and records NOTHING — the POST runs before the record, so a failed POST leaves
     * the ledger untouched.
     *
     * @return void
     */
    #[Test]
    public function submitOnAConnectErrorRecordsNothingAndDoesNotLeakTheToken(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static function (): ResponseInterface {
                throw new class('connection refused') extends RuntimeException implements ClientExceptionInterface {};
            },
        ]);

        try {
            $this->newRest($http, $ledger)->submit($this->feederRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for an unreachable finder.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('finder.example', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getTraceAsString());
        }

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A 202 acknowledging a DIFFERENT job than submitted fails the submission and records nothing, so the
     * ledger never holds a job the remote did not confirm under the submitted id.
     *
     * @return void
     */
    #[Test]
    public function submitOnAMismatchedAcknowledgementRecordsNothing(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'OTHER', 'state' => 'queued']),
        ]);

        try {
            $this->newRest($http, $ledger)->submit($this->feederRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for a mismatched acknowledgement.');
        } catch (RuntimeException) {
            // Expected: the remote acknowledged a different job.
        }

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A 202 whose body is unreadable (the remote accepted then returned garbage) fails the submission and
     * records nothing — covering the unreadable-acknowledgement operand of the postJob guard.
     *
     * @return void
     */
    #[Test]
    public function submitOnAnUnreadableAcknowledgementRecordsNothing(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([static fn (): ResponseInterface => self::raw(202, '{not json')]);

        try {
            $this->newRest($http, $ledger)->submit($this->feederRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for an unreadable acknowledgement.');
        } catch (RuntimeException) {
            // Expected: the 202 body could not be read back to confirm the job.
        }

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A job the remote accepted (202) but that is too large to record locally is DELETED remotely so it
     * never runs untracked, the failure is surfaced, and nothing is left in the ledger.
     *
     * @return void
     */
    #[Test]
    public function submitDeletesTheRemoteJobWhenItCannotBeRecorded(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'huge', 'state' => 'queued']),
            static fn (): ResponseInterface => self::json(200, []),
        ]);

        try {
            $this->newRest($http, $ledger)
                ->submit($this->feederRequest('huge', 7, array_fill(0, 12_000, 'I1234567')));
            self::fail('Expected a RuntimeException for an unrecordable oversized request.');
        } catch (RuntimeException) {
            // Expected: the entry exceeds the read-back byte cap after the remote already accepted it.
        }

        self::assertSame([], $ledger->jobIds());
        self::assertCount(2, $http->sent);
        self::assertSame('DELETE', $http->sent[1]->getMethod());
    }

    /**
     * submit attaches the bearer token to the Authorization header, and a submission that the remote
     * rejects fails WITHOUT the secret token ever appearing in the exception message or trace.
     *
     * @return void
     */
    #[Test]
    public function submitSendsABearerHeaderAndNeverLeaksTheTokenOnError(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'queued']),
        ]);

        $this->newRest($http, $ledger)->submit($this->feederRequest('job-1', 7, ['I1']));

        self::assertCount(1, $http->sent);
        self::assertSame('Bearer secret-token', $http->sent[0]->getHeaderLine('Authorization'));

        $rejecting = $this->http([static fn (): ResponseInterface => self::json(500, ['error' => 'nope'])]);

        try {
            $this->newRest($rejecting, new RestPendingLedger($this->tmp . '/rp2'))
                ->submit($this->feederRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for a rejected submission.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getTraceAsString());
        }
    }

    /**
     * release is a no-op for the REST transport: the ledger entry stays so the next drain re-polls it.
     *
     * @return void
     */
    #[Test]
    public function releaseKeepsTheLedgerEntry(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $this->newRest($this->http([]), $ledger)->release('job-1');

        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * markIngested removes the ledger entry even when the best-effort remote DELETE THROWS (a transport
     * fault), so the swallowed-Throwable cleanup can never resurrect the job into the poll set.
     *
     * @return void
     */
    #[Test]
    public function markIngestedRemovesTheLedgerEntryEvenWhenDeleteThrows(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([
            static function (): ResponseInterface {
                throw new class('delete failed') extends RuntimeException implements ClientExceptionInterface {};
            },
        ]);
        $this->newRest($http, $ledger)->markIngested('job-1', ['matchesStored' => 0]);

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * markFailed also drops the ledger entry (a REST job carries no local failure bookkeeping).
     *
     * @return void
     */
    #[Test]
    public function markFailedRemovesTheLedgerEntry(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([static fn (): ResponseInterface => self::json(500, [])]);
        $this->newRest($http, $ledger)->markFailed('job-1', 'ingest_failed');

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * inFlightRequests maps each ledger entry to the {treeId, requestedPersonIds} shape the producer
     * dedups against, and staleCount is always 0 (REST jobs are never claimed into an ingesting state).
     *
     * @return void
     */
    #[Test]
    public function inFlightRequestsMapTheLedgerAndStaleCountIsZero(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1', 'I2'], '2024-05-21T08:29:55Z');

        $transport = $this->newRest($this->http([]), $ledger);
        $inFlight  = iterator_to_array($transport->inFlightRequests());

        self::assertSame([['treeId' => 7, 'requestedPersonIds' => ['I1', 'I2']]], $inFlight);
        self::assertSame(0, $transport->staleCount());
    }

    /**
     * An unauthenticated REST connection (no token) sends NO Authorization header, so the token-null
     * branch of the request builder is exercised.
     *
     * @return void
     */
    #[Test]
    public function anUnauthenticatedConnectionSendsNoAuthorizationHeader(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'queued']),
        ]);
        $factory = new HttpFactory();

        $transport = new RestJobTransport(
            $http,
            $factory,
            $factory,
            $ledger,
            FinderConnection::rest('https://finder.example', null),
            new ResponseValidator()
        );

        $transport->submit($this->feederRequest('job-1', 7, ['I1']));

        self::assertFalse($http->sent[0]->hasHeader('Authorization'));
    }

    /**
     * Finalising a job whose id is not a path-safe filename removes the (already absent) ledger entry but
     * sends NO remote DELETE, so the guarded delete path never builds a request URL from an unvalidated
     * id.
     *
     * @return void
     */
    #[Test]
    public function finalisingAnInvalidJobIdSendsNoDeleteRequest(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([]);

        $this->newRest($http, $ledger)->markFailed('../evil', 'finder_failed');

        self::assertCount(0, $http->sent);
    }

    /**
     * Variante-B parity pin: a #56 job-response carrying a top-level `state` validates unchanged
     * through the shared ResponseValidator, which reads only schemaVersion/jobId/results.
     *
     * @return void
     */
    #[Test]
    public function theValidatorIgnoresATopLevelStateField(): void
    {
        $byPerson = (new ResponseValidator())->validate($this->doneBody('job-1', 'I1'), 'job-1', ['I1']);

        self::assertArrayHasKey('I1', $byPerson);
    }

    /**
     * Builds the REST transport wired to the given client double and ledger, using a real Guzzle
     * PSR-17 factory and a tokened REST connection.
     *
     * @param ClientInterface   $http     The scriptable PSR-18 client double.
     * @param RestPendingLedger $ledger   The in-flight ledger.
     * @param int               $maxBytes The response-body byte cap (defaults to the production 5 MiB).
     *
     * @return RestJobTransport The wired transport.
     */
    private function newRest(ClientInterface $http, RestPendingLedger $ledger, int $maxBytes = 5_242_880): RestJobTransport
    {
        $factory = new HttpFactory();

        return new RestJobTransport(
            $http,
            $factory,
            $factory,
            $ledger,
            FinderConnection::rest('https://finder.example/', 'secret-token'),
            new ResponseValidator(),
            $maxBytes
        );
    }

    /**
     * Builds a feeder request with one candidate per person id.
     *
     * @param string       $jobId     The job identifier.
     * @param int          $treeId    The tree identifier.
     * @param list<string> $personIds The requested person ids.
     *
     * @return FeederRequest The assembled request.
     */
    private function feederRequest(string $jobId, int $treeId, array $personIds): FeederRequest
    {
        $candidates = [];

        foreach ($personIds as $personId) {
            $candidates[] = new FeederCandidateRequest($personId, []);
        }

        return new FeederRequest(
            1,
            $jobId,
            new DateTimeImmutable('2024-05-21T08:29:55+00:00'),
            'de-DE',
            $candidates,
            $treeId
        );
    }

    /**
     * A minimal valid #56 done job-response carrying a top-level `state` (Variante B): one person with
     * a single well-formed notice. Mirrors ResponseValidatorTest's valid payload plus the state field.
     *
     * @param string $jobId    The job identifier the response echoes.
     * @param string $personId The requested person the notice belongs to.
     *
     * @return array<string, mixed> The done job-response body.
     */
    private function doneBody(string $jobId, string $personId): array
    {
        return [
            'schemaVersion' => 1,
            'jobId'         => $jobId,
            'state'         => 'done',
            'results'       => [
                $personId => [
                    [
                        'url'        => 'https://obituary.example/n/1',
                        'fetchedAt'  => '2024-05-21T08:30:00Z',
                        'noticeType' => 'obituary',
                        'name'       => 'Max Mustermann',
                        'source'     => 'obituary-example-de',
                    ],
                ],
            ],
        ];
    }

    /**
     * A scriptable PSR-18 client: each scripted callable is consumed in order and produces (or throws)
     * the next response, while every sent request is captured in the public $sent list for assertions.
     *
     * @param list<callable(RequestInterface): ResponseInterface> $script The scripted responders, in order.
     *
     * @return ClientInterface&object{sent: list<RequestInterface>} The capturing client double.
     */
    private function http(array $script): ClientInterface
    {
        return new class($script) implements ClientInterface {
            /**
             * @var list<RequestInterface> The requests the transport sent, in order.
             */
            public array $sent = [];

            /**
             * @param list<callable(RequestInterface): ResponseInterface> $script The scripted responders.
             */
            public function __construct(private array $script)
            {
            }

            /**
             * {@inheritDoc}
             */
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;
                $next         = array_shift($this->script);

                if ($next === null) {
                    throw new RuntimeException('Unexpected request: ' . $request->getMethod() . ' ' . $request->getUri());
                }

                return $next($request);
            }
        };
    }

    /**
     * Builds a JSON response with the given status and decoded body.
     *
     * @param int                  $status The HTTP status code.
     * @param array<string, mixed> $data   The body to JSON-encode.
     *
     * @return ResponseInterface The JSON response.
     */
    private static function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Builds a response with a raw (possibly non-JSON) body, for the undecodable-body path.
     *
     * @param int    $status The HTTP status code.
     * @param string $body   The raw body bytes.
     *
     * @return ResponseInterface The response.
     */
    private static function raw(int $status, string $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * Builds a body stream that throws on read, simulating a connection dropped mid-body (the fault a
     * lazily-streaming PSR-18 client surfaces from read(), not from sendRequest()).
     *
     * @return StreamInterface The throwing stream.
     */
    private function throwingStream(): StreamInterface
    {
        return FnStream::decorate(
            Utils::streamFor('payload'),
            [
                'eof'  => static fn (): bool => false,
                'read' => static fn (): string => throw new RuntimeException('connection reset mid-body'),
            ]
        );
    }
}
