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
use MagicSunday\ObituaryMatcher\Ui\JobStatusRowView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Pure-unit suite for the webtrees-free AND Queue-free control-panel presenter: the persisted
 * settings and tree list project through verbatim, each recent-job tuple maps to a row in input
 * order with its finish timestamp and counts preserved, the no-jobs case yields an empty row list,
 * and every job-state key passes through unchanged.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ControlPanelPresenter::class)]
#[UsesClass(ControlPanelView::class)]
#[UsesClass(FinderConnectionView::class)]
#[UsesClass(JobStatusRowView::class)]
final class ControlPanelPresenterTest extends TestCase
{
    /**
     * A throwaway finder-connection view model passed through every presenter call below.
     *
     * @return FinderConnectionView The default file-transport connection without a probe.
     */
    private static function finder(): FinderConnectionView
    {
        return new FinderConnectionView('file', '', false, null);
    }

    /**
     * The persisted settings, the tree list and the recent-job tuples project into the view: the
     * jobs keep their input order, each row carries its own finish timestamp and counts.
     *
     * @return void
     */
    #[Test]
    public function buildProjectsSettingsTreesAndJobs(): void
    {
        $view = (new ControlPanelPresenter())->build(
            80,
            25,
            [['id' => 1, 'name' => 'Demo'], ['id' => 2, 'name' => 'Test']],
            [
                ['jobId' => 'job-2', 'stateKey' => 'running', 'counts' => [], 'finishedAt' => null],
                ['jobId' => 'job-1', 'stateKey' => 'done', 'counts' => ['notices' => 3], 'finishedAt' => '2026-06-24T09:00:00Z'],
            ],
            self::finder(),
        );

        self::assertSame(80, $view->minAge);
        self::assertSame(25, $view->limit);
        self::assertCount(2, $view->trees);
        self::assertSame(['job-2', 'job-1'], array_map(static fn ($r) => $r->jobId, $view->jobRows));
        self::assertNull($view->jobRows[0]->finishedAt);
        self::assertSame(3, $view->jobRows[1]->counts['notices']);
    }

    /**
     * With no recent jobs the view carries an empty row list while the settings still project.
     *
     * @return void
     */
    #[Test]
    public function buildWithNoJobsYieldsEmptyJobRows(): void
    {
        $view = (new ControlPanelPresenter())->build(90, 50, [], [], self::finder());

        self::assertSame([], $view->jobRows);
        self::assertSame(90, $view->minAge);
    }

    /**
     * The handler-built finder-connection view model is surfaced verbatim on the control-panel view:
     * the transport and the token-is-set boolean carry through, and a GET render has no probe.
     *
     * @return void
     */
    #[Test]
    public function buildCarriesTheFinderConnectionView(): void
    {
        $finder = new FinderConnectionView('rest', 'http://finder:8080', true, null);
        $view   = (new ControlPanelPresenter())->build(90, 50, [], [], $finder);

        self::assertSame('rest', $view->finder->transport);
        self::assertSame('http://finder:8080', $view->finder->baseUrl);
        self::assertTrue($view->finder->tokenIsSet);
        self::assertNull($view->finder->probe);
    }

    /**
     * Every backing job-state key passes through the presenter onto the row verbatim.
     *
     * @param string $stateKey The job-state backing value under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('allStateKeys')]
    public function buildMapsEveryStateKeyThrough(string $stateKey): void
    {
        $view = (new ControlPanelPresenter())->build(90, 50, [], [
            ['jobId' => 'job-1', 'stateKey' => $stateKey, 'counts' => [], 'finishedAt' => null],
        ], self::finder());

        self::assertSame($stateKey, $view->jobRows[0]->stateKey);
    }

    /**
     * The seven backing job-state keys (mirroring {@see \MagicSunday\ObituaryMatcher\Queue\JobState}).
     *
     * @return array<int, array{0: string}>
     */
    public static function allStateKeys(): array
    {
        return [
            ['queued'],
            ['running'],
            ['done'],
            ['failed'],
            ['ingesting'],
            ['ingested'],
            ['failed-ingest'],
        ];
    }
}
