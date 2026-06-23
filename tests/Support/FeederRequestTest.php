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
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the serialisable, schema-versioned feeder request value object.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FeederRequest::class)]
final class FeederRequestTest extends TestCase
{
    /**
     * The numeric tree identifier passed into the constructor round-trips into the serialised array.
     */
    #[Test]
    public function treeIdRoundTripsIntoTheSerialisedArray(): void
    {
        $request = new FeederRequest(
            2,
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [],
            11,
        );

        self::assertSame(11, $request->toArray()['treeId']);
    }
}
