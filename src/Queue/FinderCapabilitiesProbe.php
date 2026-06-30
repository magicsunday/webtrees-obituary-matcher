<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

use function rtrim;

/**
 * A pure PSR-18 probe that asks a finder service for its advertised capabilities: it sends
 * `GET {baseUrl}/capabilities`, reads the body under a byte cap, narrows it through
 * {@see FinderCapabilities::tryFromArray()} and maps the whole exchange onto a {@see CapabilitiesProbeResult}.
 * The probe NEVER throws out of {@see self::probe()} — a transport fault, a non-success status, an
 * unreadable/oversized/torn body and a body that fails to narrow are each turned into a result, so the
 * admin UI that drives the probe always receives an outcome rather than an exception.
 *
 * The bearer token is a secret: it travels only in the `Authorization` header and is never written into
 * the built URL, a log line, an exception or the returned result.
 *
 * The class is pure (it lives in the {@see \MagicSunday\ObituaryMatcher\Queue} layer): it depends only on
 * `Psr\Http\*`, the {@see FinderConnection} value object and its own value objects, so it stays
 * webtrees-free and the adapters inject it through their own seam.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderCapabilitiesProbe
{
    /**
     * @var int The HTTP status a successful capabilities request must return.
     */
    private const int STATUS_OK = 200;

    /**
     * @var string The capabilities endpoint URL, with the base URL joined to the path by a single slash.
     */
    private string $url;

    /**
     * Constructor.
     *
     * @param ClientInterface         $http           The PSR-18 client the probe sends the request through.
     * @param RequestFactoryInterface $requestFactory The PSR-17 factory that builds the HTTP request.
     * @param FinderConnection        $connection     The REST connection (base URL and optional token).
     * @param int                     $maxBytes       The maximum number of response-body bytes read into memory.
     */
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requestFactory,
        private FinderConnection $connection,
        private int $maxBytes = QueueLimits::FEEDER_FILE_MAX_BYTES,
    ) {
        // rtrim the base URL before joining so a configured trailing slash never doubles into
        // `{baseUrl}//capabilities`.
        $this->url = rtrim($connection->baseUrl(), '/') . '/capabilities';
    }

    /**
     * Probes the finder for its capabilities and maps the exchange onto a result. NEVER throws: a
     * transport fault yields {@see CapabilitiesProbeResult::unreachable()}, a non-200 status yields
     * {@see CapabilitiesProbeResult::unreachable()} carrying the status, an unreadable/oversized/torn or
     * non-narrowing body yields {@see CapabilitiesProbeResult::invalid()}, and a narrowed document yields
     * {@see CapabilitiesProbeResult::reachable()}.
     *
     * @return CapabilitiesProbeResult The probe outcome.
     */
    public function probe(): CapabilitiesProbeResult
    {
        try {
            $response = $this->http->sendRequest($this->request());
        } catch (ClientExceptionInterface) {
            // The token lives only in a header, so no secret is involved in this transport-layer failure.
            return CapabilitiesProbeResult::unreachable();
        }

        $status = $response->getStatusCode();

        if ($status !== self::STATUS_OK) {
            return CapabilitiesProbeResult::unreachable($status);
        }

        $body = CappedJsonBodyReader::decode($response, $this->maxBytes);

        if ($body === null) {
            return CapabilitiesProbeResult::invalid();
        }

        $capabilities = FinderCapabilities::tryFromArray($body);

        if (!$capabilities instanceof FinderCapabilities) {
            return CapabilitiesProbeResult::invalid();
        }

        return CapabilitiesProbeResult::reachable($capabilities);
    }

    /**
     * Builds the `GET {baseUrl}/capabilities` request, attaching the bearer token in the `Authorization`
     * header when the connection is authenticated. The token never appears anywhere else (no URL, no
     * message), so a leak cannot happen through the request line.
     *
     * @return RequestInterface The built request.
     */
    private function request(): RequestInterface
    {
        $request = $this->requestFactory->createRequest('GET', $this->url);
        $token   = $this->connection->token();

        if ($token !== null) {
            return $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $request;
    }
}
