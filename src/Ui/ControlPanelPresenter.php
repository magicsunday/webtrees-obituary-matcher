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
 * The webtrees-free AND Queue-free projection engine for the admin control panel: it shapes the
 * persisted settings, the tree list and the handler-built recent-job tuples (each lifted from a
 * {@see \MagicSunday\ObituaryMatcher\Queue\JobStatus}) into a {@see ControlPanelView}, mapping every
 * tuple to a {@see JobStatusRowView} in input order. Each value passes through plain/untrusted; the
 * control-panel template escapes every sink once with e() and maps any state key to its i18n label.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ControlPanelPresenter
{
    /**
     * Shapes the persisted settings, the tree list and the recent-job tuples into a view model.
     *
     * @param int                                                                                               $minAge The persisted minimum-age setting.
     * @param int                                                                                               $limit  The persisted per-run candidate limit.
     * @param list<array{id: int, name: string}>                                                                $trees  The trees offered for a feeder trigger.
     * @param list<array{jobId: string, stateKey: string, counts: array<string, int>, finishedAt: string|null}> $jobs   The recent-job tuples (handler-built from JobStatus).
     * @param FinderConnectionView                                                                              $finder The handler-built finder-connection view model.
     *
     * @return ControlPanelView The view-ready model.
     */
    public function build(int $minAge, int $limit, array $trees, array $jobs, FinderConnectionView $finder): ControlPanelView
    {
        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = new JobStatusRowView(
                $job['jobId'],
                $job['stateKey'],
                $job['counts'],
                $job['finishedAt'],
            );
        }

        return new ControlPanelView($minAge, $limit, $trees, $rows, $finder);
    }
}
