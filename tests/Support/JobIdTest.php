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
use MagicSunday\ObituaryMatcher\Support\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;

/**
 * Tests the time-prefixed, naturally-sortable job-id minter: the format, the UTC timestamp
 * derivation and the path-pattern compatibility the drain's discovery relies on.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(JobId::class)]
final class JobIdTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function mintEmbedsTheUtcTimestampAndAnEightHexSuffix(): void
    {
        $id = JobId::mint(new DateTimeImmutable('2026-06-23T10:15:30+00:00'));

        self::assertSame(1, preg_match('/^job-20260623T101530Z-[0-9a-f]{8}$/', $id));
    }

    /**
     * @return void
     */
    #[Test]
    public function mintNormalisesANonUtcInstantToUtc(): void
    {
        // 12:15:30 +02:00 is 10:15:30 UTC — the id stamps the UTC wall time, not the local one.
        $id = JobId::mint(new DateTimeImmutable('2026-06-23T12:15:30+02:00'));

        self::assertSame(1, preg_match('/^job-20260623T101530Z-[0-9a-f]{8}$/', $id));
    }

    /**
     * @return void
     */
    #[Test]
    public function mintStaysWithinTheQueuePathPattern(): void
    {
        $id = JobId::mint(new DateTimeImmutable('2026-06-23T10:15:30+00:00'));

        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id));
    }
}
