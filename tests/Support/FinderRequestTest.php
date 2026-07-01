<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the serialisable, schema-versioned finder request value object.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderRequest::class)]
final class FinderRequestTest extends TestCase
{
    /**
     * The serialised body carries the published contract top-level: the contract MAJOR (1), the job
     * id, the locale and the candidates — and NONE of the internal envelope fields (`treeId`,
     * `createdAt`), which stay accessible on the object for the transport ledger only.
     */
    #[Test]
    public function toArrayEmitsTheContractTopLevelWithoutInternalEnvelopeFields(): void
    {
        $request = new FinderRequest(
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [],
            11,
        );

        $body = $request->toArray();

        self::assertSame(1, $body['schemaVersion']);
        self::assertSame('job-1', $body['jobId']);
        self::assertSame('de-DE', $body['locale']);
        self::assertSame([], $body['candidates']);
        self::assertArrayNotHasKey('treeId', $body);
        self::assertArrayNotHasKey('createdAt', $body);

        // The internal envelope fields remain readable on the object for the RestPendingLedger.
        self::assertSame(11, $request->treeId);
        self::assertSame('2026-06-20T00:00:00+00:00', $request->createdAt->format('Y-m-d\TH:i:sP'));
    }
}
