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
use MagicSunday\ObituaryMatcher\Queue\BodyFault;
use MagicSunday\ObituaryMatcher\Queue\CappedJsonBodyReader;
use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Queue\RestJobTransport;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_fill;
use function is_file;
use function iterator_to_array;
use function json_encode;
use function sort;
use function str_contains;
use function time;
use function touch;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the REST job transport against a scriptable PSR-18 client double: the queued->running->done
 * poll lifecycle, the 404/5xx/connect-error branches, the malformed-done -> response_invalid mapping,
 * the bearer-header-without-token-leak contract, the atomic-claim concurrency guarantee (a claimed done
 * job is not re-yielded to a second drain; a stale claim is counted), idempotent finalisation even when
 * the remote DELETE fails, and the Variant-B parity that the shared validator ignores a top-level
 * `state` field.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RestJobTransport::class)]
#[UsesClass(RestPendingLedger::class)]
#[UsesClass(BodyFault::class)]
#[UsesClass(CappedJsonBodyReader::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(JobId::class)]
#[UsesClass(CompletedJob::class)]
#[UsesClass(FailedJob::class)]
#[UsesClass(ResponseValidator::class)]
#[UsesClass(ResponseValidationException::class)]
#[UsesClass(FinderConnection::class)]
#[UsesClass(FinderRequest::class)]
#[UsesClass(FinderCandidateRequest::class)]
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
    use ScriptableHttpClientTrait;

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
        $transport->submit($this->finderRequest('job-1', 7, ['I1']));

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
     * A done job a first drain claims and yields is NOT yielded again to a concurrent second drain: the
     * claim is held once ownership is handed to the caller (the drain would finalise it), so a second
     * fetchCompleted over the same ledger sees the job still claimed and fresh and yields nothing — and
     * makes no second HTTP poll. This is the at-most-once guarantee at the transport seam.
     *
     * @return void
     */
    #[Test]
    public function aClaimedDoneJobIsNotYieldedAgainToAConcurrentDrain(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        // Exactly ONE scripted response: the second drain must not poll the remote at all.
        $http      = $this->http([fn (): ResponseInterface => self::json(200, $this->doneBody('job-1', 'I1'))]);
        $transport = $this->newRest($http, $ledger);

        $first = iterator_to_array($transport->fetchCompleted());

        self::assertCount(1, $first);
        self::assertInstanceOf(CompletedJob::class, $first[0]);

        // The job is still claimed (the caller has not finalised it yet); a concurrent drain gets nothing.
        $second = iterator_to_array($transport->fetchCompleted());

        self::assertSame([], $second);
    }

    /**
     * staleCount() reports the claims a crashed drain stranded: a claimed entry aged past the ledger's
     * stale threshold is counted, so the drain summary surfaces stuck work.
     *
     * @return void
     */
    #[Test]
    public function staleCountReflectsACrashStrandedClaim(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        self::assertTrue($ledger->claim('job-1'));
        // Backdate the claim two hours into the past (past the one-hour stale threshold).
        touch($this->tmp . '/rp/claimed/job-1.json', time() - 7_200);

        // No HTTP is issued by staleCount, so the scripted client is empty.
        self::assertSame(1, $this->newRest($this->http([]), $ledger)->staleCount());
    }

    /**
     * A transient poll (a 5xx here) RELEASES the claim through the fetchCompleted finally, so the entry
     * returns to the pending pool and the NEXT drain re-polls and completes it — rather than the claim
     * being stranded in claimed/ until the stale-reclaim timeout. This discriminates the finally-release
     * wiring: were the release removed, the first drain would leave the job fresh-claimed and the second
     * drain's claimable() would skip it, so the re-poll below would yield nothing.
     *
     * @return void
     */
    #[Test]
    public function aTransientPollReleasesTheClaimSoTheNextDrainRepollsAndCompletes(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        // First poll a transient 5xx, then (on the re-poll) the done body.
        $http = $this->http([
            static fn (): ResponseInterface => self::json(500, ['error' => 'busy']),
            fn (): ResponseInterface => self::json(200, $this->doneBody('job-1', 'I1')),
        ]);
        $transport = $this->newRest($http, $ledger);

        // First drain: the 5xx yields nothing and the finally hands the claim back to pending.
        $firstDrain = iterator_to_array($transport->fetchCompleted());

        self::assertCount(0, $firstDrain);
        self::assertTrue(is_file($this->tmp . '/rp/job-1.json'));           // back in pending
        self::assertFalse(is_file($this->tmp . '/rp/claimed/job-1.json'));  // not stranded claimed

        // Second drain re-polls the released entry and now completes it.
        $second = iterator_to_array($transport->fetchCompleted());

        self::assertCount(1, $second);
        self::assertInstanceOf(CompletedJob::class, $second[0]);
        self::assertSame('job-1', $second[0]->jobId);
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
     * A 200 whose body is not valid JSON is a PERMANENT fault: the #56 contract reproduces the stored
     * response verbatim on every re-GET, so it is terminally failed as `response_invalid` rather than
     * polled forever.
     *
     * @return void
     */
    #[Test]
    public function anUndecodableBodyIsTerminallyFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http     = $this->http([static fn (): ResponseInterface => self::raw(200, '{not json')]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
    }

    /**
     * A 200 body larger than the byte cap is a PERMANENT fault: the oversized stored response recurs on
     * every re-GET, so it is terminally failed as `response_invalid`.
     *
     * @return void
     */
    #[Test]
    public function anOversizedBodyIsTerminallyFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        // A 50-byte cap against a normal done body (well over 50 bytes) forces the oversize path.
        $http     = $this->http([fn (): ResponseInterface => self::json(200, $this->doneBody('job-1', 'I1'))]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger, 50)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
    }

    /**
     * The response body stream is released on its two value-returning read paths — the oversize read
     * (BodyFault::Permanent) and the full read (the contents) — rather than left open until garbage
     * collection, so a long-running drain cannot accumulate sockets/file descriptors. A closed Guzzle
     * stream reports `isReadable() === false`; the assertion runs while the test still holds the stream,
     * so it reflects the transport's explicit close(), not a GC teardown. The third read exit path — a
     * torn read — is pinned separately by {@see aTornBodyReadAlsoClosesTheStream()}.
     *
     * @param int $maxBytes         The response-body byte cap selecting the exit path.
     * @param int $expectedOutcomes The number of outcomes the path is expected to yield.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('bodyStreamCloseScenarios')]
    public function theResponseBodyStreamIsClosedAfterAnOversizeOrFullRead(int $maxBytes, int $expectedOutcomes): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $stream = Utils::streamFor(json_encode($this->doneBody('job-1', 'I1'), JSON_THROW_ON_ERROR));
        $http   = $this->http([
            static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        $outcomes = iterator_to_array($this->newRest($http, $ledger, $maxBytes)->fetchCompleted());

        self::assertCount($expectedOutcomes, $outcomes);
        self::assertFalse($stream->isReadable());
    }

    /**
     * The two value-returning read exit paths of {@see RestJobTransport::fetchCompleted()}: an oversized
     * body (cap below the body size) that is terminally failed as one FailedJob, and a fully read body
     * (production cap) that yields one CompletedJob. Both release the stream and yield exactly one
     * outcome.
     *
     * @return array<string, array{int, int}> The cap and the expected outcome count per path.
     */
    public static function bodyStreamCloseScenarios(): array
    {
        return [
            'oversized body terminally failed (BodyFault::Permanent)' => [50, 1],
            'fully read body (read returns the contents)'             => [5_242_880, 1],
        ];
    }

    /**
     * The third read exit path: a body read torn mid-stream (the PSR-7 stream throws on read) still
     * closes the stream. This is the exact path the close was added for — a dropped connection a
     * lazily-streaming client surfaces from read() — so the leak-prone branch is pinned explicitly. The
     * recording stream counts close() calls without a destructor, so the count reflects only the
     * transport's explicit close(), never a garbage-collection teardown.
     *
     * @return void
     */
    #[Test]
    public function aTornBodyReadAlsoClosesTheStream(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $stream = $this->recordingStream('x', readThrows: true);
        $http   = $this->http([
            static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(0, $outcomes);
        self::assertSame(1, $stream->closeCalls);
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A response body stream whose close() itself THROWS does not abort the drain: readCappedBody()
     * guards the release so its documented no-throw isolation holds. A first job whose body reads back
     * fully but whose close() throws still yields its CompletedJob (the swallowed close-fault never
     * escapes), and a second pending job in the same drain still completes — proving the loop was not
     * aborted. The response is routed by request URL so the assertion holds regardless of the unordered
     * ledger scan.
     *
     * @return void
     */
    #[Test]
    public function aThrowingStreamCloseDoesNotAbortTheDrain(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-throwclose', 7, ['I1'], '2024-05-21T08:29:55Z');
        $ledger->record('job-ok', 7, ['I1'], '2024-05-21T08:29:55Z');

        $route = fn (RequestInterface $request): ResponseInterface => str_contains((string) $request->getUri(), 'job-throwclose')
            ? new Response(
                200,
                ['Content-Type' => 'application/json'],
                $this->recordingStream(json_encode($this->doneBody('job-throwclose', 'I1'), JSON_THROW_ON_ERROR), closeThrows: true)
            )
            : self::json(200, $this->doneBody('job-ok', 'I1'));

        $transport = $this->newRest($this->http([$route, $route]), $ledger);
        $outcomes  = iterator_to_array($transport->fetchCompleted());

        $jobIds = [];

        foreach ($outcomes as $outcome) {
            self::assertInstanceOf(CompletedJob::class, $outcome);
            $jobIds[] = $outcome->jobId;
        }

        sort($jobIds);

        self::assertSame(['job-ok', 'job-throwclose'], $jobIds);
    }

    /**
     * A 200 whose `state` is missing or NOT a string carries no usable lifecycle signal and is a
     * structurally non-conforming response the contract reproduces verbatim on every re-GET, so it is
     * terminally failed as `response_invalid` rather than polled forever.
     *
     * @return void
     */
    #[Test]
    public function aMissingOrNonStringStateIsTerminallyFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http     = $this->http([static fn (): ResponseInterface => self::json(200, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 42])]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
    }

    /**
     * A 200 with an UNKNOWN but plausible `state` STRING (neither `done` nor `failed`) is still in flight
     * — a forward-compatible finder may complete it on a later poll — so it is skipped and kept in the
     * ledger, NOT terminally failed. This preserves the forward-compatibility of the state enum.
     *
     * @return void
     */
    #[Test]
    public function anUnknownStringStateLeavesTheJobInFlight(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http      = $this->http([static fn (): ResponseInterface => self::json(200, ['schemaVersion' => 1, 'jobId' => 'job-1', 'state' => 'paused'])]);
        $transport = $this->newRest($http, $ledger);

        self::assertSame([], iterator_to_array($transport->fetchCompleted()));
        self::assertSame(['job-1'], $ledger->jobIds());
    }

    /**
     * A 200 body that is valid JSON but NOT an object (a bare scalar such as `42`) is a PERMANENT fault:
     * the non-object decode guard classifies it as unusable and the stored response recurs on every
     * re-GET, so it is terminally failed as `response_invalid`.
     *
     * @return void
     */
    #[Test]
    public function aNonObjectBodyIsTerminallyFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http     = $this->http([static fn (): ResponseInterface => self::raw(200, '42')]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
    }

    /**
     * A 200 body that is valid JSON but a TOP-LEVEL ARRAY (`[1,2,3]`) is not a job-response object; the
     * list guard classifies it PERMANENT and the stored response recurs on every re-GET, so it is
     * terminally failed as `response_invalid` rather than polled forever (the `is_array` guard alone would
     * have let a JSON array through, since json_decode maps both objects and arrays to a PHP array).
     *
     * @return void
     */
    #[Test]
    public function aTopLevelJsonArrayBodyIsTerminallyFailed(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http     = $this->http([static fn (): ResponseInterface => self::raw(200, '[1,2,3]')]);
        $outcomes = iterator_to_array($this->newRest($http, $ledger)->fetchCompleted());

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(FailedJob::class, $outcomes[0]);
        self::assertSame('response_invalid', $outcomes[0]->reasonCategory);
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
            ? new Response(200, [], $this->recordingStream('x', readThrows: true))
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
            $this->newRest($http, $ledger)->submit($this->finderRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for an unreachable finder.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('finder.example', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getTraceAsString());
        }

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A 202 acknowledging a DIFFERENT job than submitted fails the submission, records nothing, and (since
     * the remote already accepted under our id per the contract) issues a best-effort compensating DELETE
     * so no untracked remote job is left behind.
     *
     * @return void
     */
    #[Test]
    public function submitOnAMismatchedAcknowledgementRecordsNothing(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::json(202, ['schemaVersion' => 1, 'jobId' => 'OTHER', 'state' => 'queued']),
            static fn (): ResponseInterface => self::json(200, []),
        ]);

        try {
            $this->newRest($http, $ledger)->submit($this->finderRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for a mismatched acknowledgement.');
        } catch (RuntimeException) {
            // Expected: the remote acknowledged a different job.
        }

        self::assertSame([], $ledger->jobIds());
        self::assertCount(2, $http->sent);
        self::assertSame('DELETE', $http->sent[1]->getMethod());
        self::assertStringContainsString('/jobs/job-1', (string) $http->sent[1]->getUri());
    }

    /**
     * A 202 whose body is unreadable (the remote accepted the job, then a proxy truncated the success
     * body) DELETES the accepted remote job so it never runs untracked — the exact orphan class the
     * record-failure path also compensates. Nothing is recorded and the failure is surfaced.
     *
     * @return void
     */
    #[Test]
    public function submitDeletesTheRemoteJobWhenTheAcknowledgementIsUnreadable(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([
            static fn (): ResponseInterface => self::raw(202, '{not json'),
            static fn (): ResponseInterface => self::json(200, []),
        ]);

        try {
            $this->newRest($http, $ledger)->submit($this->finderRequest('job-1', 7, ['I1']));
            self::fail('Expected a RuntimeException for an unreadable acknowledgement.');
        } catch (RuntimeException) {
            // Expected: the 202 body could not be read back to confirm the job.
        }

        self::assertSame([], $ledger->jobIds());
        self::assertCount(2, $http->sent);
        self::assertSame('DELETE', $http->sent[1]->getMethod());
        self::assertStringContainsString('/jobs/job-1', (string) $http->sent[1]->getUri());
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
                ->submit($this->finderRequest('huge', 7, array_fill(0, 12_000, 'I1234567')));
            self::fail('Expected a RuntimeException for an unrecordable oversized request.');
        } catch (RuntimeException) {
            // Expected: the entry exceeds the read-back byte cap after the remote already accepted it.
        }

        self::assertSame([], $ledger->jobIds());
        self::assertCount(2, $http->sent);
        self::assertSame('DELETE', $http->sent[1]->getMethod());
        self::assertStringContainsString('/jobs/huge', (string) $http->sent[1]->getUri());
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

        $this->newRest($http, $ledger)->submit($this->finderRequest('job-1', 7, ['I1']));

        self::assertCount(1, $http->sent);
        self::assertSame('Bearer secret-token', $http->sent[0]->getHeaderLine('Authorization'));

        $rejecting = $this->http([static fn (): ResponseInterface => self::json(500, ['error' => 'nope'])]);

        try {
            $this->newRest($rejecting, new RestPendingLedger($this->tmp . '/rp2'))
                ->submit($this->finderRequest('job-1', 7, ['I1']));
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
     * markFailed drops the ledger entry but PRESERVES the remote job — it sends NO DELETE, so a transient
     * local ingest fault (surfacing as `ingest_failed`) does not destroy the only copy of the finder's
     * result. This mirrors the file transport, which parks a failed job in failed-ingest/ with its payload
     * retained; only a successful markIngested deletes the remote.
     *
     * @return void
     */
    #[Test]
    public function markFailedRemovesTheLedgerEntryButPreservesTheRemoteJob(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $ledger->record('job-1', 7, ['I1'], '2024-05-21T08:29:55Z');

        $http = $this->http([]);
        $this->newRest($http, $ledger)->markFailed('job-1', 'ingest_failed');

        self::assertSame([], $ledger->jobIds());
        self::assertCount(0, $http->sent);
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

        $transport->submit($this->finderRequest('job-1', 7, ['I1']));

        self::assertFalse($http->sent[0]->hasHeader('Authorization'));
    }

    /**
     * Finalising a successful job whose id is not a path-safe filename removes the (already absent) ledger
     * entry but sends NO remote DELETE, so the guarded delete path in markIngested never builds a request
     * URL from an unvalidated id.
     *
     * @return void
     */
    #[Test]
    public function finalisingAnInvalidJobIdSendsNoDeleteRequest(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rp');
        $http   = $this->http([]);

        $this->newRest($http, $ledger)->markIngested('../evil', ['matchesStored' => 0]);

        self::assertCount(0, $http->sent);
    }

    /**
     * Variant-B parity pin: a #56 job-response carrying a top-level `state` validates unchanged
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
     * Builds a finder request with one candidate per person id.
     *
     * @param string       $jobId     The job identifier.
     * @param int          $treeId    The tree identifier.
     * @param list<string> $personIds The requested person ids.
     *
     * @return FinderRequest The assembled request.
     */
    private function finderRequest(string $jobId, int $treeId, array $personIds): FinderRequest
    {
        $candidates = [];

        foreach ($personIds as $personId) {
            $candidates[] = new FinderCandidateRequest(
                $personId,
                new PersonName([], null, 'Mustermann', null),
                []
            );
        }

        return new FinderRequest(
            $jobId,
            new DateTimeImmutable('2024-05-21T08:29:55+00:00'),
            'de-DE',
            $candidates,
            $treeId
        );
    }

    /**
     * A minimal valid #56 done job-response carrying a top-level `state` (Variant B): one person with
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
}
