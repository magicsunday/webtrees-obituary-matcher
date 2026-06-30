<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\ControlPanelPresenter;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelView;
use MagicSunday\ObituaryMatcher\Ui\FinderConnectionView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit suite for the webtrees-free AND Queue-free control-panel presenter: the persisted
 * settings, tree list, open-job count and finder view project through verbatim into the view model.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ControlPanelPresenter::class)]
#[UsesClass(ControlPanelView::class)]
#[UsesClass(FinderConnectionView::class)]
final class ControlPanelPresenterTest extends TestCase
{
    /**
     * A throwaway finder-connection view model passed through every presenter call below.
     *
     * @return FinderConnectionView An unconfigured connection without a probe.
     */
    private static function finder(): FinderConnectionView
    {
        return new FinderConnectionView('', false, null);
    }

    /**
     * The persisted settings, the tree list and the open-job count project into the view.
     *
     * @return void
     */
    #[Test]
    public function buildProjectsSettingsTreesAndOpenJobCount(): void
    {
        $view = (new ControlPanelPresenter())->build(
            80,
            25,
            [['id' => 1, 'name' => 'Demo'], ['id' => 2, 'name' => 'Test']],
            4,
            self::finder(),
        );

        self::assertSame(80, $view->minAge);
        self::assertSame(25, $view->limit);
        self::assertCount(2, $view->trees);
        self::assertSame(4, $view->openJobCount);
    }

    /**
     * With no open jobs the view carries a zero count while the settings still project.
     *
     * @return void
     */
    #[Test]
    public function buildWithNoOpenJobsYieldsZeroCount(): void
    {
        $view = (new ControlPanelPresenter())->build(90, 50, [], 0, self::finder());

        self::assertSame(0, $view->openJobCount);
        self::assertSame(90, $view->minAge);
    }

    /**
     * The handler-built finder-connection view model is surfaced verbatim on the control-panel view:
     * the base URL and the token-is-set boolean carry through, and a GET render has no probe.
     *
     * @return void
     */
    #[Test]
    public function buildCarriesTheFinderConnectionView(): void
    {
        $finder = new FinderConnectionView('http://finder:8080', true, null);
        $view   = (new ControlPanelPresenter())->build(90, 50, [], 0, $finder);

        self::assertSame('http://finder:8080', $view->finder->baseUrl);
        self::assertTrue($view->finder->tokenIsSet);
        self::assertNull($view->finder->probe);
    }
}
