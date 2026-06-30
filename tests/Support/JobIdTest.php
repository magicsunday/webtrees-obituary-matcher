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
use function str_repeat;

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
     * Verifies a minted id has the `job-<UTC compact timestamp>Z-<eight hex>` shape, so the time
     * prefix makes the id naturally sortable oldest-first and the random suffix keeps it unique.
     *
     * @return void
     */
    #[Test]
    public function mintEmbedsTheUtcTimestampAndAnEightHexSuffix(): void
    {
        $id = JobId::mint(new DateTimeImmutable('2026-06-23T10:15:30+00:00'));

        self::assertSame(1, preg_match('/^job-20260623T101530Z-[0-9a-f]{8}$/', $id));
    }

    /**
     * Verifies a non-UTC instant is normalised to UTC before stamping, so the embedded timestamp is
     * the UTC wall time regardless of the source offset and the ids stay globally comparable.
     *
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
     * Verifies a minted id satisfies the queue's job-id path-traversal pattern, so the drain's
     * directory discovery and the QueuePaths builders accept it without escaping the queue root.
     *
     * @return void
     */
    #[Test]
    public function mintStaysWithinTheQueuePathPattern(): void
    {
        $id = JobId::mint(new DateTimeImmutable('2026-06-23T10:15:30+00:00'));

        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id));
    }

    /**
     * Verifies a well-formed job identifier is accepted as a path-safe storage filename basename.
     *
     * @return void
     */
    #[Test]
    public function aWellFormedJobIdIsSafe(): void
    {
        self::assertTrue(JobId::isSafeForStorage('job-20260630T101530Z-a1b2c3d4'));
    }

    /**
     * Verifies an empty, traversal or over-long job identifier is rejected before it reaches the
     * filesystem.
     *
     * @return void
     */
    #[Test]
    public function aTraversalOrEmptyJobIdIsRejected(): void
    {
        self::assertFalse(JobId::isSafeForStorage(''));
        self::assertFalse(JobId::isSafeForStorage('../etc'));
        self::assertFalse(JobId::isSafeForStorage('a/b'));
        self::assertFalse(JobId::isSafeForStorage(str_repeat('a', 65)));
    }

    /**
     * Verifies the `/D` anchoring: a trailing newline is NOT swallowed by `$` and must be rejected.
     *
     * @return void
     */
    #[Test]
    public function aTrailingNewlineJobIdIsRejected(): void
    {
        self::assertFalse(JobId::isSafeForStorage("job-ok\n"));
    }
}
