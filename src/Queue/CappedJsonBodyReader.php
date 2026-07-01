<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function is_array;
use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Reads an untrusted PSR-7 response body under a byte cap and decodes it to a JSON object, or returns a
 * {@see BodyFault} when the body is unusable — {@see BodyFault::Transient} for a torn mid-read a re-GET
 * may recover, {@see BodyFault::Permanent} for a body that exceeds the cap, is not valid JSON, or does
 * not decode to an object. The reader closes the body stream on EVERY exit path and never throws, so its
 * callers can isolate a single unusable body without aborting their wider loop.
 *
 * The same capped-read discipline is needed by both the REST job transport (which polls `GET /jobs/{id}`)
 * and the capabilities probe (which fetches `GET /capabilities`); it lives here so both consume one
 * audited implementation rather than each mirroring the byte cap, the torn-read isolation and the
 * close-on-every-path release.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class CappedJsonBodyReader
{
    /**
     * Constructor.
     *
     * Private: the reader is a stateless static utility, never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Reads the response body under the byte cap and decodes it to an associative array, or returns a
     * {@see BodyFault} classifying why it is unusable: {@see BodyFault::Transient} for a torn mid-read a
     * re-GET may recover, {@see BodyFault::Permanent} for a body that exceeds the cap, is not valid JSON,
     * or does not decode to a JSON object. Never throws.
     *
     * @param ResponseInterface $response The response whose body is read.
     * @param int               $maxBytes The maximum number of body bytes read into memory.
     *
     * @return array<int|string, mixed>|BodyFault The decoded object, or the fault classifying why it is
     *                                            unusable.
     */
    public static function decode(ResponseInterface $response, int $maxBytes): array|BodyFault
    {
        $contents = self::readCappedBody($response, $maxBytes);

        if ($contents instanceof BodyFault) {
            return $contents;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return BodyFault::Permanent;
        }

        if (!is_array($decoded)) {
            return BodyFault::Permanent;
        }

        return $decoded;
    }

    /**
     * Reads a response body stream into memory, capped at $maxBytes, or returns a {@see BodyFault} when
     * the stream carries more than the cap allows ({@see BodyFault::Permanent}) OR a read fault interrupts
     * it ({@see BodyFault::Transient}). Reading $maxBytes + 1 bytes lets the cap be enforced on the bytes
     * actually read rather than a (spoofable) Content-Length header.
     *
     * A PSR-7 stream may throw on read (a lazily-streaming PSR-18 client performs the transfer during
     * read(), so a connection dropped mid-body surfaces here, NOT from sendRequest()). That fault is caught
     * and turned into a {@see BodyFault::Transient} result so a single torn response is isolated — never
     * escaping to abort the caller's loop — while remaining recoverable on a later re-GET.
     *
     * @param ResponseInterface $response The response whose body stream is read.
     * @param int               $maxBytes The maximum number of body bytes read into memory.
     *
     * @return string|BodyFault The body bytes, {@see BodyFault::Permanent} when the body exceeds the cap,
     *                          or {@see BodyFault::Transient} when a read fault interrupts it.
     */
    private static function readCappedBody(ResponseInterface $response, int $maxBytes): string|BodyFault
    {
        $stream   = $response->getBody();
        $contents = '';

        try {
            while (!$stream->eof()) {
                $chunk = $stream->read($maxBytes + 1 - strlen($contents));

                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;

                if (strlen($contents) > $maxBytes) {
                    return BodyFault::Permanent;
                }
            }
        } catch (Throwable) {
            // A torn/interrupted body read is isolated like any other unusable body: skip, never abort.
            // It is transient — a later re-GET may recover it — so the caller keeps retrying.
            return BodyFault::Transient;
        } finally {
            // Release the body stream on every exit path — oversize, torn read, or full read. A
            // lazily-streaming PSR-18 client holds the underlying socket open until the stream is closed,
            // so leaving it to garbage collection would accumulate sockets/file descriptors across a
            // long-running drain. $contents is already an in-memory copy, so closing here does not affect
            // the returned value.
            //
            // The close() is itself guarded: it must NOT break this method's documented no-throw isolation.
            // A client whose stream close() throws — or, under webtrees' warning-to-exception handler,
            // warns — would otherwise escape the catch above and defeat that isolation, so a close failure
            // is swallowed (its only cost is the resource lifetime the close was added to bound).
            try {
                $stream->close();
            } catch (Throwable) {
                // Intentionally swallowed: a failed release must not abort the caller's loop.
            }
        }

        return $contents;
    }
}
