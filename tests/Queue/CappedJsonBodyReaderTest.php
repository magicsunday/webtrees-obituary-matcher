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
use MagicSunday\ObituaryMatcher\Queue\BodyFault;
use MagicSunday\ObituaryMatcher\Queue\CappedJsonBodyReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared capped JSON body reader: a valid JSON object decodes to an associative array, while
 * every unusable body maps to a typed {@see BodyFault} that tells the caller whether a re-GET may
 * recover it. A torn mid-read is {@see BodyFault::Transient}; an oversized, malformed or non-object body
 * is {@see BodyFault::Permanent}. The reader closes the body stream on every path and never throws.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CappedJsonBodyReader::class)]
#[UsesClass(BodyFault::class)]
final class CappedJsonBodyReaderTest extends TestCase
{
    use ScriptableHttpClientTrait;

    /**
     * A 200 body that is a valid JSON object decodes to the associative array it carries.
     *
     * @return void
     */
    #[Test]
    public function aValidObjectDecodesToAnArray(): void
    {
        $decoded = CappedJsonBodyReader::decode(self::json(200, ['state' => 'done']), 5_242_880);

        self::assertSame(['state' => 'done'], $decoded);
    }

    /**
     * A body read torn mid-stream (the PSR-7 stream throws on read) is a TRANSIENT fault: a later re-GET
     * may recover it, so the caller keeps retrying. The stream is still released.
     *
     * @return void
     */
    #[Test]
    public function aTornReadIsTransient(): void
    {
        $stream = $this->recordingStream('x', readThrows: true);

        $fault = CappedJsonBodyReader::decode(
            new Response(200, ['Content-Type' => 'application/json'], $stream),
            5_242_880
        );

        self::assertSame(BodyFault::Transient, $fault);
        self::assertSame(1, $stream->closeCalls);
    }

    /**
     * A body larger than the byte cap is a PERMANENT fault: the contract reproduces it verbatim on every
     * re-GET, so it must be terminally failed rather than polled forever.
     *
     * @return void
     */
    #[Test]
    public function anOversizedBodyIsPermanent(): void
    {
        // A 4-byte cap against a body well over 4 bytes forces the oversize path.
        $fault = CappedJsonBodyReader::decode(self::raw(200, '{"state":"done"}'), 4);

        self::assertSame(BodyFault::Permanent, $fault);
    }

    /**
     * A body that is not valid JSON is a PERMANENT fault.
     *
     * @return void
     */
    #[Test]
    public function aMalformedJsonBodyIsPermanent(): void
    {
        $fault = CappedJsonBodyReader::decode(self::raw(200, '{not json'), 5_242_880);

        self::assertSame(BodyFault::Permanent, $fault);
    }

    /**
     * A body that is valid JSON but not a JSON object (a bare scalar) is a PERMANENT fault.
     *
     * @return void
     */
    #[Test]
    public function aNonObjectBodyIsPermanent(): void
    {
        $fault = CappedJsonBodyReader::decode(self::raw(200, '42'), 5_242_880);

        self::assertSame(BodyFault::Permanent, $fault);
    }
}
