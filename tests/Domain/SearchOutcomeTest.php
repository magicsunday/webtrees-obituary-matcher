<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Domain\SearchOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-person search-outcome classification derived from a person's portal coverage and the
 * number of notices found — the distinction §6.5 needs to tell a genuine miss from a portal outage.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SearchOutcome::class)]
#[UsesClass(PortalCoverage::class)]
final class SearchOutcomeTest extends TestCase
{
    /**
     * A portal that found notices (noticeCount > 0) means the person has something to review — Found
     * wins over every other coverage state, even a concurrent portal outage.
     *
     * @return void
     */
    #[Test]
    public function foundWhenAPortalReportedNotices(): void
    {
        $coverage = [
            self::portal('a', CoverageStatus::Ok, 2),
            self::portal('b', CoverageStatus::Failed),
        ];

        self::assertSame(SearchOutcome::Found, SearchOutcome::fromCoverage($coverage));
    }

    /**
     * With no notices, a single failed portal makes the outcome PortalFailed: the search was incomplete,
     * so the silence is NOT a confirmed miss (the user should be offered a retry).
     *
     * @return void
     */
    #[Test]
    public function portalFailedWhenNoNoticesAndAnyPortalFailed(): void
    {
        $coverage = [
            self::portal('a', CoverageStatus::Ok, 0),
            self::portal('b', CoverageStatus::Failed),
        ];

        self::assertSame(SearchOutcome::PortalFailed, SearchOutcome::fromCoverage($coverage));
    }

    /**
     * With no notices, no failure and at least one portal searched OK, the outcome is NoNotices — a
     * genuine miss (everything reachable was searched and nothing was found).
     *
     * @return void
     */
    #[Test]
    public function noNoticesWhenSearchedOkAndNothingFound(): void
    {
        $coverage = [
            self::portal('a', CoverageStatus::Ok, 0),
            self::portal('b', CoverageStatus::Skipped),
        ];

        self::assertSame(SearchOutcome::NoNotices, SearchOutcome::fromCoverage($coverage));
    }

    /**
     * With no notices and every portal merely skipped (or an empty coverage), nothing was actually
     * searched, so the outcome is Skipped — no new information, distinct from a genuine miss.
     *
     * @return void
     */
    #[Test]
    public function skippedWhenNothingWasActuallySearched(): void
    {
        self::assertSame(
            SearchOutcome::Skipped,
            SearchOutcome::fromCoverage([self::portal('a', CoverageStatus::Skipped)]),
        );

        self::assertSame(SearchOutcome::Skipped, SearchOutcome::fromCoverage([]));
    }

    /**
     * Builds a coverage entry for the given portal id, status and optional notice count.
     *
     * @param string         $portal      The portal identifier.
     * @param CoverageStatus $status      The coverage status.
     * @param int|null       $noticeCount The notice count the portal reported.
     *
     * @return PortalCoverage The coverage entry.
     */
    private static function portal(string $portal, CoverageStatus $status, ?int $noticeCount = null): PortalCoverage
    {
        return new PortalCoverage($portal, $status, $noticeCount, null);
    }
}
