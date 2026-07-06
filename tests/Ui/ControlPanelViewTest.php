<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Domain\ScoreWeights;
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
#[UsesClass(ScoreWeights::class)]
final class ControlPanelViewTest extends TestCase
{
    /**
     * The view holds the persisted settings, the offered trees, the open-job count, the finder view, the
     * scoring weights and the read-only band thresholds, each mapping to the expected named property in
     * positional order.
     *
     * @return void
     */
    #[Test]
    public function controlPanelViewHoldsSettingsTreesAndOpenJobCount(): void
    {
        $finder  = new FinderConnectionView('https://finder.example', false, null);
        $weights = new ScoreWeights(40, 20, 12, 8, 44, 6);
        $bands   = ['strong' => 85, 'probable' => 70, 'possible' => 55, 'weak' => 40];
        $view    = new ControlPanelView(90, 50, [['id' => 1, 'name' => 'Demo']], 7, $finder, $weights, $bands);

        self::assertSame(90, $view->minAge);
        self::assertSame(50, $view->limit);
        self::assertSame('Demo', $view->trees[0]['name']);
        self::assertSame(7, $view->openJobCount);
        self::assertSame('https://finder.example', $view->finder->baseUrl);
        self::assertSame(40, $view->weights->maxName);
        self::assertSame(6, $view->weights->ambiguityGap);
        self::assertSame(85, $view->bandThresholds['strong']);
        self::assertSame(40, $view->bandThresholds['weak']);
    }
}
