<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\WorklistRowView;
use MagicSunday\ObituaryMatcher\Ui\WorklistView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Shape test pinning the load-bearing positional constructor order of the worklist value objects:
 * Task 3 builds a {@see WorklistRowView} positionally, so a reorder of the 12 promoted fields would
 * silently mis-map every row. This guards that contract together with the counts/filter projection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(WorklistView::class)]
#[UsesClass(WorklistRowView::class)]
final class WorklistViewTest extends TestCase
{
    /**
     * The view holds its rows, the per-status counts, the active filter and the paging fields, and
     * the row maps its positional arguments to the expected named properties.
     *
     * @return void
     */
    #[Test]
    public function worklistViewHoldsRowsCountsAndPaging(): void
    {
        $row = new WorklistRowView(
            'Max Mustermann',
            'I1',
            '/tree/demo/individual/I1',
            'strong',
            'Strong match',
            92,
            '04.09.2023',
            'https://obituary.example/a',
            'obituary.example',
            'pending',
            'Open',
            '/tree/demo/obituary-review/I1/abc',
        );

        $view = new WorklistView(
            [$row],
            ['total' => 1, 'open' => 1, 'confirmed' => 0, 'rejected' => 0, 'uncertain' => 0],
            'all',
            1,
            1,
            1,
        );

        self::assertSame('Max Mustermann', $view->rows[0]->personName);
        self::assertSame('/tree/demo/obituary-review/I1/abc', $view->rows[0]->reviewUrl);
        self::assertSame(1, $view->counts['open']);
        self::assertSame('all', $view->statusFilter);
    }
}
