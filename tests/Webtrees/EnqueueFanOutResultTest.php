<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Webtrees\EnqueueFanOutResult;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the multi-finder enqueue fan-out aggregation (§5.2f): the per-finder summaries are summed and
 * their non-null job ids collected in order — the arithmetic the CLI adapter relies on.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnqueueFanOutResult::class)]
#[UsesClass(EnqueueSummary::class)]
final class EnqueueFanOutResultTest extends TestCase
{
    /**
     * Two finders' summaries are summed and both job ids collected, in fan-out order.
     *
     * @return void
     */
    #[Test]
    public function sumsTheCountsAndCollectsBothJobIds(): void
    {
        $result = EnqueueFanOutResult::fromSummaries([
            new EnqueueSummary('job-a', 3, 1, 2, 4),
            new EnqueueSummary('job-b', 4, 2, 0, 5),
        ]);

        self::assertSame(['job-a', 'job-b'], $result->jobIds);
        self::assertSame(7, $result->candidates);
        self::assertSame(3, $result->skippedInflight);
        self::assertSame(2, $result->excludedHosts);
        self::assertSame(9, $result->suppressed);
    }

    /**
     * A finder that wrote no job (null job id — everything suppressed or in flight) contributes its
     * counts but no job id, so the collected ids reflect only the finders that actually submitted.
     *
     * @return void
     */
    #[Test]
    public function excludesANullJobIdWhileStillSummingItsCounts(): void
    {
        $result = EnqueueFanOutResult::fromSummaries([
            new EnqueueSummary('job-a', 2, 0, 1, 0),
            new EnqueueSummary(null, 0, 5, 0, 7),
        ]);

        self::assertSame(['job-a'], $result->jobIds);
        self::assertSame(2, $result->candidates);
        self::assertSame(5, $result->skippedInflight);
        self::assertSame(1, $result->excludedHosts);
        self::assertSame(7, $result->suppressed);
    }

    /**
     * No summaries (no finders resolved — a guarded-against case) aggregate to an empty, zeroed result.
     *
     * @return void
     */
    #[Test]
    public function aggregatesAnEmptyRunToZero(): void
    {
        $result = EnqueueFanOutResult::fromSummaries([]);

        self::assertSame([], $result->jobIds);
        self::assertSame(0, $result->candidates);
        self::assertSame(0, $result->skippedInflight);
        self::assertSame(0, $result->excludedHosts);
        self::assertSame(0, $result->suppressed);
    }
}
