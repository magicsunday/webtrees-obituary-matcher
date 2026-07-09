<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Support\CoverageMerge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-portal coverage union across finders (§5.2c): a portal is `ok` if ANY finder covered it,
 * `failed` only when at least one finder tried and none succeeded, and `skipped` when no finder searched
 * it; a merged `ok` carries the highest notice count any single finder reported. The union collapses the
 * possibly-duplicated per-finder rows into exactly one row per portal, deterministically ordered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CoverageMerge::class)]
#[UsesClass(PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
final class CoverageMergeTest extends TestCase
{
    /**
     * A portal one finder covered OK and another reported failed merges to `ok`: the portal WAS covered,
     * so it must not read as an outage. This is the core §5.2c fix — a single finder's portal failure must
     * not mask another finder's successful coverage of the same portal.
     *
     * @return void
     */
    #[Test]
    public function okWinsOverFailedForTheSamePortal(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('trauer', CoverageStatus::Failed, null, 'timeout'),
            new PortalCoverage('trauer', CoverageStatus::Ok, 2, null),
        ]);

        self::assertCount(1, $merged);
        self::assertSame('trauer', $merged[0]->portal);
        self::assertSame(CoverageStatus::Ok, $merged[0]->status);
        self::assertSame(2, $merged[0]->noticeCount);
    }

    /**
     * A portal every finder that tried it failed stays `failed`, carrying a failure message.
     *
     * @return void
     */
    #[Test]
    public function failedWhenEveryFinderFailedThePortal(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('trauer', CoverageStatus::Failed, null, 'timeout'),
            new PortalCoverage('trauer', CoverageStatus::Failed, null, '503'),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(CoverageStatus::Failed, $merged[0]->status);
        self::assertNotNull($merged[0]->message);
    }

    /**
     * `ok` wins over `skipped`: a portal one finder searched OK and another skipped merges to `ok`.
     *
     * @return void
     */
    #[Test]
    public function okWinsOverSkipped(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('trauer', CoverageStatus::Skipped, null, null),
            new PortalCoverage('trauer', CoverageStatus::Ok, 0, null),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(CoverageStatus::Ok, $merged[0]->status);
    }

    /**
     * `failed` wins over `skipped`: a portal one finder failed and another skipped merges to `failed`
     * (it was attempted, and no finder succeeded).
     *
     * @return void
     */
    #[Test]
    public function failedWinsOverSkipped(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('trauer', CoverageStatus::Skipped, null, null),
            new PortalCoverage('trauer', CoverageStatus::Failed, null, 'timeout'),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(CoverageStatus::Failed, $merged[0]->status);
    }

    /**
     * A merged `ok` carries the HIGHEST notice count any single finder reported for that portal, never a
     * sum (two finders searching the same portal see overlapping notices, so summing would double-count).
     *
     * @return void
     */
    #[Test]
    public function mergedOkTakesTheMaxNoticeCount(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('trauer', CoverageStatus::Ok, 2, null),
            new PortalCoverage('trauer', CoverageStatus::Ok, 3, null),
        ]);

        self::assertCount(1, $merged);
        self::assertSame(3, $merged[0]->noticeCount);
    }

    /**
     * Distinct portals from different finders all survive the union, deterministically ordered by portal
     * id so the report and its tests are stable.
     *
     * @return void
     */
    #[Test]
    public function distinctPortalsAllSurviveOrderedByPortalId(): void
    {
        $merged = CoverageMerge::union([
            new PortalCoverage('zebra', CoverageStatus::Ok, 1, null),
            new PortalCoverage('alpha', CoverageStatus::Ok, 1, null),
        ]);

        self::assertCount(2, $merged);
        self::assertSame('alpha', $merged[0]->portal);
        self::assertSame('zebra', $merged[1]->portal);
    }

    /**
     * An empty input unions to an empty list.
     *
     * @return void
     */
    #[Test]
    public function emptyInputUnionsToEmpty(): void
    {
        self::assertSame([], CoverageMerge::union([]));
    }
}
