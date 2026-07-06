<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\ScoreWeights;

/**
 * The webtrees-free AND Queue-free projection engine for the admin control panel: it shapes the
 * persisted settings, the tree list, the open finder-job count, the finder-connection view model and
 * the admin-editable scoring weights (plus the read-only band thresholds) into a {@see ControlPanelView}.
 * Each value passes through plain/untrusted; the control-panel template escapes every sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ControlPanelPresenter
{
    /**
     * Shapes the persisted settings, the tree list, the open finder-job count, the finder view and the
     * scoring weights into a view model.
     *
     * @param int                                                         $minAge         The persisted minimum-age setting.
     * @param int                                                         $limit          The persisted per-run candidate limit.
     * @param list<array{id: int, name: string}>                          $trees          The trees offered for a finder trigger.
     * @param int                                                         $openJobCount   The number of open (not-yet-drained) finder jobs.
     * @param FinderConnectionView                                        $finder         The handler-built finder-connection view model.
     * @param ScoreWeights                                                $weights        The admin-editable scoring weights.
     * @param array{strong: int, probable: int, possible: int, weak: int} $bandThresholds The fixed, read-only band thresholds.
     *
     * @return ControlPanelView The view-ready model.
     */
    public function build(
        int $minAge,
        int $limit,
        array $trees,
        int $openJobCount,
        FinderConnectionView $finder,
        ScoreWeights $weights,
        array $bandThresholds,
    ): ControlPanelView {
        return new ControlPanelView($minAge, $limit, $trees, $openJobCount, $finder, $weights, $bandThresholds);
    }
}
