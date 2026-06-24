<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

/**
 * A read-only, view-ready projection of a page of stored matches for the tree-wide worklist screen.
 * It carries the projected rows, the per-status counts over the surviving rows, the active status
 * filter and the paging fields. Every value is plain/untrusted; the worklist template escapes each
 * sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class WorklistView
{
    /**
     * Constructor.
     *
     * @param list<WorklistRowView>                                                       $rows          The page of rows.
     * @param array{total: int, open: int, confirmed: int, rejected: int, uncertain: int} $counts        The per-status counts over the surviving rows.
     * @param string                                                                      $statusFilter  The active status filter.
     * @param int                                                                         $page          The 1-based current page.
     * @param int                                                                         $totalPages    The total page count (>= 1).
     * @param int                                                                         $totalFiltered The total rows after filtering.
     */
    public function __construct(
        public array $rows,
        public array $counts,
        public string $statusFilter,
        public int $page,
        public int $totalPages,
        public int $totalFiltered,
    ) {
    }
}
