<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Shared PSR-18 test doubles for the queue's HTTP-driven transports: a scriptable capturing client, the
 * JSON/raw response builders and a recording body-stream double whose read()/close() can be made to throw.
 * Both {@see RestJobTransportTest} and {@see FinderCapabilitiesProbeTest} drive the same capped-read and
 * bearer-header contracts, so the doubles live here as one fixture rather than each test mirroring them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
trait ScriptableHttpClientTrait
{
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
        return new ScriptablePsr18Client($script);
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
     * Builds a PSR-7 body stream double that COUNTS close() calls (`$closeCalls`) and can be configured
     * to throw on read (a torn mid-body read) or on close (a release fault). It decorates a real Guzzle
     * stream via {@see StreamDecoratorTrait} and overrides only close()/read(); the trait supplies the
     * remaining StreamInterface methods. The trait has NO `__destruct` (unlike
     * {@see \GuzzleHttp\Psr7\FnStream}, whose destructor invokes the close handler), so `$closeCalls`
     * reflects only the caller's EXPLICIT close(), never a garbage-collection teardown that would
     * false-green a close assertion.
     *
     * @param string $body        The body bytes the stream serves.
     * @param bool   $readThrows  Whether read() throws, simulating a connection dropped mid-body.
     * @param bool   $closeThrows Whether close() throws, simulating a release fault.
     *
     * @return StreamInterface&object{closeCalls: int} The recording stream double.
     */
    private function recordingStream(string $body, bool $readThrows = false, bool $closeThrows = false): StreamInterface
    {
        return new class(Utils::streamFor($body), $readThrows, $closeThrows) implements StreamInterface {
            use StreamDecoratorTrait;

            /**
             * @var int The number of explicit close() calls the caller made.
             */
            public int $closeCalls = 0;

            /**
             * @param StreamInterface $stream      The real stream the trait delegates the unoverridden methods to.
             * @param bool            $readThrows  Whether read() throws (a torn mid-body read).
             * @param bool            $closeThrows Whether close() throws (a release fault).
             */
            public function __construct(
                private StreamInterface $stream,
                private bool $readThrows,
                private bool $closeThrows,
            ) {
            }

            /**
             * Records the call, optionally throws to simulate a release fault, then releases the real
             * stream.
             *
             * @return void
             */
            public function close(): void
            {
                ++$this->closeCalls;

                if ($this->closeThrows) {
                    throw new RuntimeException('stream close failed');
                }

                $this->stream->close();
            }

            /**
             * Reads from the real stream, or throws to simulate a connection dropped mid-body.
             *
             * @param int $length The maximum number of bytes to read.
             *
             * @return string The next body chunk.
             */
            public function read(int $length): string
            {
                if ($this->readThrows) {
                    throw new RuntimeException('connection reset mid-body');
                }

                return $this->stream->read($length);
            }
        };
    }
}
