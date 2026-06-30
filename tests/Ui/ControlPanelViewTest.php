<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\ControlPanelView;
use MagicSunday\ObituaryMatcher\Ui\FinderConnectionView;
use MagicSunday\ObituaryMatcher\Ui\JobStatusRowView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Shape test pinning the load-bearing positional constructor order of the control-panel value
 * objects: the presenter and handler build both {@see ControlPanelView} and {@see JobStatusRowView}
 * positionally, so a reorder of the promoted fields would silently mis-map every setting and row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ControlPanelView::class)]
#[UsesClass(FinderConnectionView::class)]
#[UsesClass(JobStatusRowView::class)]
final class ControlPanelViewTest extends TestCase
{
    /**
     * The view holds the persisted settings, the offered trees and the recent-job rows, and the
     * nested row maps its positional arguments to the expected named properties.
     *
     * @return void
     */
    #[Test]
    public function controlPanelViewHoldsSettingsTreesAndJobRows(): void
    {
        $row    = new JobStatusRowView('job-1', 'done', ['candidates' => 5], '2026-06-24T10:00:00Z');
        $finder = new FinderConnectionView('file', '', false, null);
        $view   = new ControlPanelView(90, 50, [['id' => 1, 'name' => 'Demo']], [$row], $finder);

        self::assertSame(90, $view->minAge);
        self::assertSame(50, $view->limit);
        self::assertSame('Demo', $view->trees[0]['name']);
        self::assertSame('done', $view->jobRows[0]->stateKey);
        self::assertSame(5, $view->jobRows[0]->counts['candidates']);
        self::assertSame('file', $view->finder->transport);
    }
}
