<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;

use function ksort;
use function max;

use const SORT_STRING;

/**
 * Unions the per-portal coverage of one person across several finders into exactly one row per portal
 * (§5.2c). A portal is `ok` if ANY finder covered it (so one finder's failure never masks another's
 * success), `failed` only when at least one finder tried it and none succeeded, and `skipped` when no
 * finder searched it. A merged `ok` carries the HIGHEST notice count any single finder reported for the
 * portal — never a sum, since two finders searching the same portal see overlapping notices. The result
 * is ordered by portal id so the coverage report and its consumers stay deterministic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class CoverageMerge
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Unions the given per-finder coverage rows into one merged row per portal.
     *
     * @param list<PortalCoverage> $coverage The per-finder coverage rows (a portal may appear more than
     *                                       once, once per finder that reported it).
     *
     * @return list<PortalCoverage> One merged row per distinct portal, ordered by portal id.
     */
    public static function union(array $coverage): array
    {
        // array-key, not string: PHP coerces a purely-numeric portal id (e.g. "123") used as an array
        // key back to int, so the (string) cast below on the foreach key is load-bearing, not redundant.
        /** @var array<array-key, list<PortalCoverage>> $byPortal */
        $byPortal = [];

        foreach ($coverage as $row) {
            $byPortal[$row->portal][] = $row;
        }

        ksort($byPortal, SORT_STRING);

        $merged = [];

        foreach ($byPortal as $portal => $rows) {
            $merged[] = self::mergePortal((string) $portal, $rows);
        }

        return $merged;
    }

    /**
     * Collapses every finder's row for a single portal into one merged row, applying the
     * ok-over-failed-over-skipped precedence.
     *
     * @param string               $portal The portal the rows belong to.
     * @param list<PortalCoverage> $rows   The per-finder rows for that portal (at least one).
     *
     * @return PortalCoverage The merged row.
     */
    private static function mergePortal(string $portal, array $rows): PortalCoverage
    {
        $anyOk         = false;
        $anyFailed     = false;
        $maxNotice     = null;
        $failedMessage = null;

        foreach ($rows as $row) {
            if ($row->status === CoverageStatus::Ok) {
                $anyOk = true;

                if ($row->noticeCount !== null) {
                    $maxNotice = ($maxNotice === null) ? $row->noticeCount : max($maxNotice, $row->noticeCount);
                }
            } elseif ($row->status === CoverageStatus::Failed) {
                $anyFailed = true;

                if (($failedMessage === null) && ($row->message !== null)) {
                    $failedMessage = $row->message;
                }
            }
        }

        if ($anyOk) {
            return new PortalCoverage($portal, CoverageStatus::Ok, $maxNotice, null);
        }

        if ($anyFailed) {
            return new PortalCoverage($portal, CoverageStatus::Failed, null, $failedMessage);
        }

        return new PortalCoverage($portal, CoverageStatus::Skipped, null, null);
    }
}
