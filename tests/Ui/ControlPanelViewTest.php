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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Shape test pinning the load-bearing positional constructor order of the control-panel view: the
 * presenter and handler build {@see ControlPanelView} positionally, so a reorder of the promoted fields
 * would silently mis-map every setting, the open-job count or the finder view.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ControlPanelView::class)]
#[UsesClass(FinderConnectionView::class)]
final class ControlPanelViewTest extends TestCase
{
    /**
     * The view holds the persisted settings, the offered trees, the open-job count and the finder view,
     * each mapping to the expected named property in positional order.
     *
     * @return void
     */
    #[Test]
    public function controlPanelViewHoldsSettingsTreesAndOpenJobCount(): void
    {
        $finder = new FinderConnectionView('https://finder.example', false, null);
        $view   = new ControlPanelView(90, 50, [['id' => 1, 'name' => 'Demo']], 7, $finder);

        self::assertSame(90, $view->minAge);
        self::assertSame(50, $view->limit);
        self::assertSame('Demo', $view->trees[0]['name']);
        self::assertSame(7, $view->openJobCount);
        self::assertSame('https://finder.example', $view->finder->baseUrl);
    }
}
