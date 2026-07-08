<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Webtrees\DrainFanOutResult;
use MagicSunday\ObituaryMatcher\Webtrees\DrainSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the multi-finder drain fan-out aggregation (§5.2f): the per-finder drain summaries are summed
 * and a failure at ANY finder surfaces through {@see DrainFanOutResult::hasFailure()} — the arithmetic
 * and exit-code signal the CLI adapter relies on.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DrainFanOutResult::class)]
#[UsesClass(DrainSummary::class)]
final class DrainFanOutResultTest extends TestCase
{
    /**
     * Two finders' drain summaries are summed field by field.
     *
     * @return void
     */
    #[Test]
    public function sumsEachCountAcrossFinders(): void
    {
        $result = DrainFanOutResult::fromSummaries([
            new DrainSummary(2, 1, 0, 5, 0),
            new DrainSummary(3, 0, 1, 4, 2),
        ]);

        self::assertSame(5, $result->ingested);
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $result->failed);
        self::assertSame(9, $result->stored);
        self::assertSame(2, $result->stale);
    }

    /**
     * A failure at ANY finder makes the run report a failure (the CLI maps this to a non-zero exit), even
     * when the other finders succeeded.
     *
     * @return void
     */
    #[Test]
    public function reportsAFailureWhenAnyFinderFailed(): void
    {
        $clean = DrainFanOutResult::fromSummaries([
            new DrainSummary(2, 0, 0, 3, 0),
            new DrainSummary(1, 0, 0, 1, 0),
        ]);

        self::assertFalse($clean->hasFailure());

        $withFailure = DrainFanOutResult::fromSummaries([
            new DrainSummary(2, 0, 0, 3, 0),
            new DrainSummary(0, 0, 1, 0, 0),
        ]);

        self::assertTrue($withFailure->hasFailure());
    }

    /**
     * No summaries aggregate to a zeroed, failure-free result.
     *
     * @return void
     */
    #[Test]
    public function aggregatesAnEmptyRunToZeroWithoutFailure(): void
    {
        $result = DrainFanOutResult::fromSummaries([]);

        self::assertSame(0, $result->ingested);
        self::assertSame(0, $result->stored);
        self::assertFalse($result->hasFailure());
    }
}
