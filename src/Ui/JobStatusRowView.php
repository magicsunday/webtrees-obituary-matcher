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
 * A single recent-job status row, projected webtrees-free AND Queue-free. The view carries only the
 * job-state KEY, not its label: the i18n label is a literal `I18N::translate()` call in the control
 * panel template (keyed by this), so xgettext can extract it and the presenter stays decoupled.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class JobStatusRowView
{
    /**
     * Constructor.
     *
     * @param string             $jobId      The queue job id.
     * @param string             $stateKey   The job state backing value (the template maps it to a label).
     * @param array<string, int> $counts     The worker's per-metric counts (rendered generically).
     * @param string|null        $finishedAt The ISO finish timestamp, or null for a non-terminal job.
     */
    public function __construct(
        public string $jobId,
        public string $stateKey,
        public array $counts,
        public ?string $finishedAt,
    ) {
    }
}
