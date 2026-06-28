<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The REST {@see JobTransport}: it submits feeder jobs to a remote HTTP endpoint and polls their
 * outcome, mapping the transport-neutral lifecycle onto plain HTTP over a PSR-18 client. Unlike the
 * file transport there is no shared queue directory to scan, so each submitted job is remembered in a
 * local {@see RestPendingLedger} and a drain polls `GET /jobs/{id}` for every still-pending entry.
 *
 * The remote endpoint speaks the published #56 contract directly: `GET /jobs/{id}` returns the whole
 * `job-response` document — a top-level `state` plus `results` once the state is `done`. The body is
 * handed to the SAME {@see ResponseValidator} the file transport uses, which reads only
 * `schemaVersion`/`jobId`/`results` and ignores the extra `state` key, so a REST body and a file
 * `response.json` validate byte-identically and the per-job reason categories stay uniform across the
 * transport seam.
 *
 * The bearer token is a secret: it travels only in the `Authorization` header and is never written
 * into a built URL, an exception message or a log line — a transport-level failure is reported with
 * the base URL alone.
 *
 * The class is pure (it lives in the {@see \MagicSunday\ObituaryMatcher\Queue} layer): it depends only
 * on `Psr\Http\*`, the {@see RestPendingLedger}, the {@see ResponseValidator} and its own value
 * objects, so it stays webtrees-free and the adapters inject it through the {@see JobTransport} seam.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RestJobTransport implements JobTransport
{
    /**
     * @var int The HTTP status a successful job submission must return.
     */
    private const int STATUS_ACCEPTED = 202;

    /**
     * @var int The HTTP status that means the remote job no longer exists.
     */
    private const int STATUS_NOT_FOUND = 404;

    /**
     * @var int The HTTP status a successful job poll returns.
     */
    private const int STATUS_OK = 200;

    /**
     * @var string The REST base URL, normalised without a trailing slash so path joins never double it.
     */
    private string $baseUrl;

    /**
     * Constructor.
     *
     * @param ClientInterface         $http           The PSR-18 client the transport sends requests through.
     * @param RequestFactoryInterface $requestFactory The PSR-17 factory that builds the HTTP requests.
     * @param StreamFactoryInterface  $streamFactory  The PSR-17 factory that builds the request bodies.
     * @param RestPendingLedger       $ledger         The local record of in-flight REST jobs to poll.
     * @param FinderConnection        $connection     The REST connection (base URL and optional token).
     * @param ResponseValidator       $validator      The shared, transport-neutral response validator.
     * @param int                     $maxBytes       The maximum number of response-body bytes read into memory.
     *
     * @throws InvalidArgumentException When the connection carries no base URL (not a REST connection).
     */
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private RestPendingLedger $ledger,
        private FinderConnection $connection,
        private ResponseValidator $validator,
        private int $maxBytes = QueueLimits::FEEDER_FILE_MAX_BYTES,
    ) {
        $baseUrl = $connection->baseUrl();

        if ($baseUrl === null) {
            throw new InvalidArgumentException(
                'RestJobTransport requires a REST connection carrying a base URL.'
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * {@inheritDoc}
     *
     * Submits the request as `POST {baseUrl}/jobs` and records it in the local ledger so a later drain
     * can poll it. The remote must answer 202 and echo back the same jobId; a non-202 status or a
     * mismatched/absent jobId is a submission failure reported with the base URL only (never the token).
     *
     * @param FeederRequest $request The request to submit.
     *
     * @return string The submitted job's identifier.
     *
     * @throws RuntimeException When the submission is refused, the response is unreadable or its jobId
     *                          does not match the submitted job.
     */
    public function submit(FeederRequest $request): string
    {
        $httpRequest = $this->request('POST', $this->baseUrl . '/jobs')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($this->encode($request->toArray())));

        try {
            $response = $this->http->sendRequest($httpRequest);
        } catch (ClientExceptionInterface) {
            // The token lives only in a header, so it is absent from the base URL reported here.
            throw new RuntimeException(
                sprintf('Failed to reach the obituary finder at %s.', $this->baseUrl)
            );
        }

        if ($response->getStatusCode() !== self::STATUS_ACCEPTED) {
            throw new RuntimeException(
                sprintf(
                    'Obituary finder refused the job submission (HTTP %d) at %s.',
                    $response->getStatusCode(),
                    $this->baseUrl
                )
            );
        }

        $body = $this->decodeBody($response);

        if (($body === null) || (($body['jobId'] ?? null) !== $request->jobId)) {
            throw new RuntimeException(
                sprintf('Obituary finder acknowledged a different job than submitted at %s.', $this->baseUrl)
            );
        }

        $this->ledger->record(
            $request->jobId,
            $request->treeId,
            $this->personIdsOf($request),
            $request->createdAt->format(DateTimeInterface::ATOM)
        );

        return $request->jobId;
    }

    /**
     * {@inheritDoc}
     *
     * Polls `GET {baseUrl}/jobs/{jobId}` for every pending ledger entry and maps the lifecycle:
     * a connect/timeout fault or any non-200/404 status (a transient 5xx) leaves the entry untouched
     * and yields nothing; a 404 yields a `finder_job_missing` {@see FailedJob}; a 200 is decoded and
     * branched on the top-level `state` — `queued`/`running` skip, `failed` yields `finder_failed`,
     * and `done` validates the body (a validation reject → `response_invalid`, otherwise a
     * {@see CompletedJob}). An undecodable or oversized 200 body, or an unknown state, is treated as
     * not-yet-ready and skipped so a job is only ever terminally failed on an explicit signal.
     *
     * @return iterable<CompletedJob|FailedJob> The per-job outcomes.
     */
    public function fetchCompleted(): iterable
    {
        foreach ($this->ledger->entries() as $entry) {
            $jobId     = $entry['jobId'];
            $treeId    = $entry['treeId'];
            $personIds = $entry['requestedPersonIds'];

            try {
                $response = $this->http->sendRequest(
                    $this->request('GET', $this->baseUrl . '/jobs/' . $jobId)
                );
            } catch (ClientExceptionInterface) {
                // Transient transport fault: keep the ledger entry and retry on the next drain.
                continue;
            }

            $status = $response->getStatusCode();

            if ($status === self::STATUS_NOT_FOUND) {
                yield new FailedJob($jobId, $treeId, $personIds, 'finder_job_missing');

                continue;
            }

            if ($status !== self::STATUS_OK) {
                // A 5xx or any other unexpected status is transient: leave the entry for the next drain.
                continue;
            }

            $body = $this->decodeBody($response);

            if ($body === null) {
                // An undecodable or oversized 200 body is not a terminal signal — skip and retry.
                continue;
            }

            $state = $body['state'] ?? null;

            if (!is_string($state)) {
                continue;
            }

            if ($state === 'failed') {
                yield new FailedJob($jobId, $treeId, $personIds, 'finder_failed');

                continue;
            }

            if ($state !== 'done') {
                // queued/running or an unknown state: still in flight, retry on the next drain.
                continue;
            }

            try {
                $notices = $this->validator->validate($body, $jobId, $personIds);
            } catch (ResponseValidationException) {
                yield new FailedJob($jobId, $treeId, $personIds, 'response_invalid');

                continue;
            }

            yield new CompletedJob($jobId, $treeId, $personIds, $notices);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Forgets the job locally and asks the remote to delete it (best effort). The ledger removal is the
     * authoritative local state, so it happens first and unconditionally; the remote DELETE is wrapped
     * so a failure or error response can never resurrect the entry. The ingest counts and warnings are
     * the file transport's status bookkeeping and have no REST equivalent — the remote already holds
     * the job's own result.
     *
     * @param string             $jobId    The job identifier to finalise.
     * @param array<string, int> $counts   The per-metric ingest counts (unused by the REST transport).
     * @param list<string>       $warnings The non-fatal warnings (unused by the REST transport).
     *
     * @return void
     */
    public function markIngested(string $jobId, array $counts, array $warnings = []): void
    {
        $this->forget($jobId);
    }

    /**
     * {@inheritDoc}
     *
     * Forgets the job locally and asks the remote to delete it (best effort), exactly as
     * {@see self::markIngested()} — a REST job carries no local failure bookkeeping, so the category
     * and warnings have nowhere to persist and the entry is simply dropped from the poll set.
     *
     * @param string       $jobId          The job identifier to fail.
     * @param string       $reasonCategory The snake_case category classifying the failure (unused here).
     * @param list<string> $warnings       The non-fatal warnings (unused by the REST transport).
     *
     * @return void
     */
    public function markFailed(string $jobId, string $reasonCategory, array $warnings = []): void
    {
        $this->forget($jobId);
    }

    /**
     * {@inheritDoc}
     *
     * A no-op for the REST transport: a polled job is never "claimed", so there is nothing to hand back
     * — the ledger entry stays and the next drain re-polls it.
     *
     * @param string $jobId The job identifier to release.
     *
     * @return void
     */
    public function release(string $jobId): void
    {
        // Intentionally empty: REST jobs are not claimed, so the ledger entry already persists.
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<array{treeId: int, requestedPersonIds: list<string>}> The in-flight requests.
     */
    public function inFlightRequests(): iterable
    {
        foreach ($this->ledger->entries() as $entry) {
            yield [
                'treeId'             => $entry['treeId'],
                'requestedPersonIds' => $entry['requestedPersonIds'],
            ];
        }
    }

    /**
     * {@inheritDoc}
     *
     * Always 0: REST jobs are never claimed into an ingesting state, so none can be stranded mid-ingest
     * — a pending job simply stays pollable in the ledger.
     *
     * @return int The stale job count (always 0 for the REST transport).
     */
    public function staleCount(): int
    {
        return 0;
    }

    /**
     * Drops the job from the local ledger and then asks the remote to delete it (best effort). The
     * removal runs first and unconditionally; the DELETE is wrapped so neither a transport fault nor an
     * error status can keep the job in the poll set.
     *
     * @param string $jobId The job identifier to forget.
     *
     * @return void
     */
    private function forget(string $jobId): void
    {
        $this->ledger->remove($jobId);

        try {
            $this->http->sendRequest($this->request('DELETE', $this->baseUrl . '/jobs/' . $jobId));
        } catch (Throwable) {
            // Best effort: the local ledger is already cleared, so a failed remote delete is non-fatal.
        }
    }

    /**
     * Builds an HTTP request for the given method and URL, attaching the bearer token in the
     * `Authorization` header when the connection is authenticated. The token never appears anywhere
     * else (no URL, no message), so a leak cannot happen through the request line.
     *
     * @param string $method The HTTP method.
     * @param string $url    The absolute request URL.
     *
     * @return RequestInterface The built request.
     */
    private function request(string $method, string $url): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);
        $token   = $this->connection->token();

        if ($token !== null) {
            return $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $request;
    }

    /**
     * Reads a response body under the byte cap and decodes it to an associative array, or returns null
     * when the body exceeds the cap, is not valid JSON, or does not decode to a JSON object. Mirrors the
     * file reader's cap so a REST body that passes here would also pass the on-disk reader.
     *
     * @param ResponseInterface $response The response whose body is read.
     *
     * @return array<int|string, mixed>|null The decoded object, or null when it is unusable.
     */
    private function decodeBody(ResponseInterface $response): ?array
    {
        $contents = $this->readCappedBody($response);

        if ($contents === null) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Reads a response body stream into memory, capped at $maxBytes, or returns null when the stream
     * carries more than the cap allows. Reading $maxBytes + 1 bytes lets the cap be enforced on the
     * bytes actually read rather than a (spoofable) Content-Length header.
     *
     * @param ResponseInterface $response The response whose body stream is read.
     *
     * @return string|null The body bytes, or null when the body exceeds the cap.
     */
    private function readCappedBody(ResponseInterface $response): ?string
    {
        $stream   = $response->getBody();
        $contents = '';

        while (!$stream->eof()) {
            $chunk = $stream->read($this->maxBytes + 1 - strlen($contents));

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $this->maxBytes) {
                return null;
            }
        }

        return $contents;
    }

    /**
     * Encodes a JSON-ready payload, mirroring the queue's write flags so the bytes match the rest of
     * the queue layer.
     *
     * @param array<string, mixed> $payload The payload to encode.
     *
     * @return string The encoded JSON.
     *
     * @throws RuntimeException When the payload cannot be encoded.
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new RuntimeException('Failed to encode the feeder request payload.');
        }
    }

    /**
     * Extracts the requested person ids from a feeder request's candidate bundles.
     *
     * @param FeederRequest $request The request whose candidate person ids are collected.
     *
     * @return list<string> The requested person ids, in request order.
     */
    private function personIdsOf(FeederRequest $request): array
    {
        $personIds = [];

        foreach ($request->candidates as $candidate) {
            $personIds[] = $candidate->personId;
        }

        return $personIds;
    }
}
