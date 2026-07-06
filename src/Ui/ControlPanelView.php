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
 * The control-panel view, projected webtrees-free AND Queue-free. It carries the persisted settings,
 * the trees offered for a finder trigger, the number of open finder jobs, the finder-connection view
 * model and the admin-editable scoring weights with the read-only band thresholds; the template escapes
 * every sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ControlPanelView
{
    /**
     * Constructor.
     *
     * @param int                                                         $minAge         The persisted minimum-age setting.
     * @param int                                                         $limit          The persisted per-run candidate limit.
     * @param list<array{id: int, name: string}>                          $trees          The trees offered for a finder trigger.
     * @param int                                                         $openJobCount   The number of open (not-yet-drained) finder jobs.
     * @param FinderConnectionView                                        $finder         The finder-connection view model.
     * @param ScoreWeights                                                $weights        The admin-editable scoring weights.
     * @param array{strong: int, probable: int, possible: int, weak: int} $bandThresholds The fixed, read-only band thresholds.
     */
    public function __construct(
        public int $minAge,
        public int $limit,
        public array $trees,
        public int $openJobCount,
        public FinderConnectionView $finder,
        public ScoreWeights $weights,
        public array $bandThresholds,
    ) {
    }
}
