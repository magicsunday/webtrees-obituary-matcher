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
 * Reads an untrusted PSR-7 response body under a byte cap and decodes it to a JSON object, or returns
 * null when the body exceeds the cap, is torn mid-read, is not valid JSON, or does not decode to an
 * object. The reader closes the body stream on EVERY exit path and never throws, so its callers can
 * isolate a single unusable body without aborting their wider loop.
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
     * Reads the response body under the byte cap and decodes it to an associative array, or returns null
     * when the body exceeds the cap, is torn mid-read, is not valid JSON, or does not decode to a JSON
     * object. Never throws.
     *
     * @param ResponseInterface $response The response whose body is read.
     * @param int               $maxBytes The maximum number of body bytes read into memory.
     *
     * @return array<int|string, mixed>|null The decoded object, or null when it is unusable.
     */
    public static function decode(ResponseInterface $response, int $maxBytes): ?array
    {
        $contents = self::readCappedBody($response, $maxBytes);

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
     * carries more than the cap allows OR a read fault interrupts it. Reading $maxBytes + 1 bytes lets the
     * cap be enforced on the bytes actually read rather than a (spoofable) Content-Length header.
     *
     * A PSR-7 stream may throw on read (a lazily-streaming PSR-18 client performs the transfer during
     * read(), so a connection dropped mid-body surfaces here, NOT from sendRequest()). That fault is caught
     * and turned into a null result so a single torn response is isolated like an oversized or undecodable
     * one — never escaping to abort the caller's loop.
     *
     * @param ResponseInterface $response The response whose body stream is read.
     * @param int               $maxBytes The maximum number of body bytes read into memory.
     *
     * @return string|null The body bytes, or null when the body exceeds the cap or a read fault occurs.
     */
    private static function readCappedBody(ResponseInterface $response, int $maxBytes): ?string
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
                    return null;
                }
            }
        } catch (Throwable) {
            // A torn/interrupted body read is isolated like any other unusable body: skip, never abort.
            return null;
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
