<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use MagicSunday\ObituaryMatcher\Queue\BodyFault;
use MagicSunday\ObituaryMatcher\Queue\CapabilitiesProbeResult;
use MagicSunday\ObituaryMatcher\Queue\CappedJsonBodyReader;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilities;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilitiesProbe;
use MagicSunday\ObituaryMatcher\Queue\FinderPortal;
use MagicSunday\ObituaryMatcher\Queue\ProbeStatus;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Tests the pure PSR-18 finder capabilities probe against a scriptable client double: a valid body maps
 * to a reachable result, a connect fault and a non-200 status map to unreachable (the latter carrying the
 * status), and an undecodable/oversized/torn/non-narrowing body maps to invalid. The probe NEVER throws
 * out of {@see FinderCapabilitiesProbe::probe()}, the request targets `{baseUrl}/capabilities` with a
 * single slash, and the secret bearer token travels only in the Authorization header — never leaking into
 * the result on a failure path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderCapabilitiesProbe::class)]
#[UsesClass(BodyFault::class)]
#[UsesClass(CapabilitiesProbeResult::class)]
#[UsesClass(CappedJsonBodyReader::class)]
#[UsesClass(FinderCapabilities::class)]
#[UsesClass(FinderPortal::class)]
#[UsesClass(FinderConnection::class)]
final class FinderCapabilitiesProbeTest extends TestCase
{
    use ScriptableHttpClientTrait;

    /**
     * A 200 body that narrows to a valid capabilities document yields a reachable result carrying the
     * parsed capabilities.
     *
     * @return void
     */
    #[Test]
    public function aValidCapabilitiesBodyIsReachable(): void
    {
        $http   = $this->http([static fn (): ResponseInterface => self::json(200, self::validCaps())]);
        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Reachable, $result->status);
        self::assertNull($result->httpStatus);
        self::assertInstanceOf(FinderCapabilities::class, $result->capabilities);
        self::assertSame('finder-1', $result->capabilities->finderId);
    }

    /**
     * A transport-layer fault (the PSR-18 client throws a ClientExceptionInterface) yields an unreachable
     * result with no HTTP status, and never escapes as an exception.
     *
     * @return void
     */
    #[Test]
    public function aConnectErrorIsUnreachable(): void
    {
        $http = $this->http([
            static function (): ResponseInterface {
                throw new class('connect timed out') extends RuntimeException implements ClientExceptionInterface {};
            },
        ]);

        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Unreachable, $result->status);
        self::assertNull($result->httpStatus);
    }

    /**
     * A non-200 status yields an unreachable result that carries the observed HTTP status.
     *
     * @return void
     */
    #[Test]
    public function aNon200IsUnreachableWithStatus(): void
    {
        $http   = $this->http([static fn (): ResponseInterface => self::json(503, [])]);
        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Unreachable, $result->status);
        self::assertSame(503, $result->httpStatus);
    }

    /**
     * A 200 body that is not valid JSON yields an invalid result.
     *
     * @return void
     */
    #[Test]
    public function aMalformedBodyIsInvalid(): void
    {
        $http   = $this->http([static fn (): ResponseInterface => self::raw(200, '{not json')]);
        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Invalid, $result->status);
        self::assertNull($result->capabilities);
    }

    /**
     * A 200 body larger than the byte cap is not read into memory and yields an invalid result.
     *
     * @return void
     */
    #[Test]
    public function anOversizedBodyIsInvalid(): void
    {
        // A 50-byte cap against a normal capabilities body (well over 50 bytes) forces the oversize path.
        $http   = $this->http([static fn (): ResponseInterface => self::json(200, self::validCaps())]);
        $result = $this->newProbe($http, 50)->probe();

        self::assertSame(ProbeStatus::Invalid, $result->status);
    }

    /**
     * A body read torn mid-stream (the PSR-7 stream throws on read, as a lazily-streaming client surfaces
     * a dropped connection) is isolated to an invalid result — it never escapes as an exception.
     *
     * @return void
     */
    #[Test]
    public function aTornBodyReadIsInvalid(): void
    {
        $stream = $this->recordingStream('x', readThrows: true);
        $http   = $this->http([
            static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Invalid, $result->status);
        self::assertSame(1, $stream->closeCalls);
    }

    /**
     * The probe joins the base URL and the capabilities path with a single slash even when the base URL
     * carries a trailing slash, so the sent request targets `{baseUrl}/capabilities`.
     *
     * @return void
     */
    #[Test]
    public function theRequestTargetsTheCapabilitiesPathWithASingleSlash(): void
    {
        $http = $this->http([static fn (): ResponseInterface => self::json(200, self::validCaps())]);

        $factory = new HttpFactory();
        $probe   = new FinderCapabilitiesProbe(
            $http,
            $factory,
            FinderConnection::rest('http://finder:8080/', null)
        );

        $probe->probe();

        self::assertCount(1, $http->sent);
        self::assertSame('GET', $http->sent[0]->getMethod());
        self::assertSame('http://finder:8080/capabilities', (string) $http->sent[0]->getUri());
    }

    /**
     * The bearer token travels only in the Authorization header of the sent request, and a failing probe
     * (a non-200) carries the token nowhere in its result.
     *
     * @return void
     */
    #[Test]
    public function theBearerHeaderIsSentAndTheTokenNeverLeaks(): void
    {
        $http   = $this->http([static fn (): ResponseInterface => self::json(503, [])]);
        $result = $this->newProbe($http)->probe();

        self::assertCount(1, $http->sent);
        self::assertSame('Bearer secret-token', $http->sent[0]->getHeaderLine('Authorization'));

        // The token never reaches the result on a failure path.
        self::assertSame(ProbeStatus::Unreachable, $result->status);
        self::assertSame(503, $result->httpStatus);
        self::assertNull($result->capabilities);
    }

    /**
     * An unauthenticated REST connection (no token) sends NO Authorization header, exercising the
     * token-null branch of the request builder.
     *
     * @return void
     */
    #[Test]
    public function anUnauthenticatedConnectionSendsNoAuthorizationHeader(): void
    {
        $http    = $this->http([static fn (): ResponseInterface => self::json(200, self::validCaps())]);
        $factory = new HttpFactory();

        $probe = new FinderCapabilitiesProbe(
            $http,
            $factory,
            FinderConnection::rest('https://finder.example', null)
        );

        $probe->probe();

        self::assertFalse($http->sent[0]->hasHeader('Authorization'));
    }

    /**
     * A 200 body that is valid JSON but does NOT narrow to a capabilities document (a missing required
     * key) yields an invalid result.
     *
     * @return void
     */
    #[Test]
    public function aNonNarrowingBodyIsInvalid(): void
    {
        $http   = $this->http([static fn (): ResponseInterface => self::json(200, ['retentionSeconds' => 10])]);
        $result = $this->newProbe($http)->probe();

        self::assertSame(ProbeStatus::Invalid, $result->status);
        self::assertNull($result->capabilities);
    }

    /**
     * Builds the probe wired to the given client double, a real Guzzle PSR-17 request factory and a
     * tokened REST connection.
     *
     * @param ClientInterface $http     The scriptable PSR-18 client double.
     * @param int             $maxBytes The response-body byte cap (defaults to the production 5 MiB).
     *
     * @return FinderCapabilitiesProbe The wired probe.
     */
    private function newProbe(ClientInterface $http, int $maxBytes = 5_242_880): FinderCapabilitiesProbe
    {
        return new FinderCapabilitiesProbe(
            $http,
            new HttpFactory(),
            FinderConnection::rest('https://finder.example/', 'secret-token'),
            $maxBytes
        );
    }

    /**
     * A minimal valid capabilities body: one finder, one schema version and one well-formed portal.
     *
     * @return array<string, mixed> The valid capabilities document.
     */
    private static function validCaps(): array
    {
        return [
            'finderId'         => 'finder-1',
            'retentionSeconds' => 86_400,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p-de', 'name' => 'P (DE)', 'country' => 'DE', 'regions' => ['R1']]],
        ];
    }
}
