<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * The per-person outcome of a finder search, derived from the person's {@see PortalCoverage} and the
 * number of notices found. It gives the UI the §6.5 distinction that "nothing to show" hides: a genuine
 * miss (searched everywhere, found nothing) reads differently from a portal outage (an incomplete search
 * whose silence is NOT a confirmed miss) and from a deliberately-skipped search (no new information).
 * This is a DERIVED classification, recomputed from the stored coverage — never persisted itself.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum SearchOutcome
{
    /**
     * At least one notice was found — the person has suggestions to review.
     */
    case Found;

    /**
     * No notices, and at least one portal failed — the search was incomplete, so the silence is not a
     * confirmed miss and a retry should be offered.
     */
    case PortalFailed;

    /**
     * No notices, no failure, and at least one portal searched successfully — a genuine miss.
     */
    case NoNotices;

    /**
     * No notices and nothing actually searched (every portal skipped, or no coverage reported) — no new
     * information, distinct from a genuine miss.
     */
    case Skipped;

    /**
     * Classifies a person's search outcome from their per-portal coverage alone — the notice total is
     * the sum of the `ok` portals' authoritative counts (what the finder actually found), independent of
     * how many suggestions later survived review. Found wins whenever any notice was found; otherwise a
     * failed portal yields PortalFailed, a clean search with no notices yields NoNotices, and a search
     * where nothing was actually run yields Skipped.
     *
     * @param list<PortalCoverage> $coverage The person's per-portal coverage.
     *
     * @return self The classified outcome.
     */
    public static function fromCoverage(array $coverage): self
    {
        $anyFailed   = false;
        $searchedOk  = false;
        $noticeTotal = 0;

        foreach ($coverage as $portal) {
            if ($portal->status === CoverageStatus::Failed) {
                $anyFailed = true;
            }

            if ($portal->status === CoverageStatus::Ok) {
                $searchedOk = true;
                $noticeTotal += $portal->noticeCount ?? 0;
            }
        }

        if ($noticeTotal > 0) {
            return self::Found;
        }

        if ($anyFailed) {
            return self::PortalFailed;
        }

        return $searchedOk ? self::NoNotices : self::Skipped;
    }
}
