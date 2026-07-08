<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

/**
 * The aggregated tally of one multi-finder enqueue fan-out (§5.2f): the job ids submitted across every
 * finder (one per finder that wrote a job), plus the summed per-finder candidate / in-flight-skip /
 * excluded-host counts. A single-finder run aggregates exactly one {@see EnqueueSummary}, so the result
 * mirrors that summary. Extracting the summation here keeps the arithmetic testable rather than inlined
 * in the CLI adapter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class EnqueueFanOutResult
{
    /**
     * Constructor.
     *
     * @param list<string> $jobIds          The ids of the jobs submitted this run (one per finder that wrote a job).
     * @param int          $candidates      The total candidates written across all finders' requests.
     * @param int          $skippedInflight The total in-flight candidates skipped across all finders.
     * @param int          $excludedHosts   The total excluded-host entries across all finders' requests.
     */
    public function __construct(
        public array $jobIds,
        public int $candidates,
        public int $skippedInflight,
        public int $excludedHosts,
    ) {
    }

    /**
     * Aggregates the per-finder enqueue summaries of one fan-out run into a single tally, collecting the
     * non-null job ids in order and summing the counts.
     *
     * @param list<EnqueueSummary> $summaries The per-finder summaries, in fan-out order.
     *
     * @return self The aggregated fan-out result.
     */
    public static function fromSummaries(array $summaries): self
    {
        $jobIds        = [];
        $candidates    = 0;
        $skipped       = 0;
        $excludedHosts = 0;

        foreach ($summaries as $summary) {
            if ($summary->jobId !== null) {
                $jobIds[] = $summary->jobId;
            }

            $candidates    += $summary->candidates;
            $skipped       += $summary->skippedInflight;
            $excludedHosts += $summary->excludedHosts;
        }

        return new self($jobIds, $candidates, $skipped, $excludedHosts);
    }
}
