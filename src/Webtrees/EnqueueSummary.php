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
 * The aggregated tally of one {@see EnqueueService::enqueue()} run: the enqueued job id (or null
 * when no candidate survived the filter), the number of candidates written into the request, the
 * number skipped by the in-flight dedup, the total excluded-host entries emitted, and the number
 * suppressed by the negative-memory re-search policy (§5.2d).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class EnqueueSummary
{
    /**
     * Constructor.
     *
     * @param string|null $jobId           The enqueued job id, or null when no job was written.
     * @param int         $candidates      The candidates written into the request (post-filter, post-cap).
     * @param int         $skippedInflight The in-flight candidates stepped over while filling the capped
     *                                     batch (those ordered before the cap boundary); the producer
     *                                     stops hydrating once --limit survivors are gathered, so this is
     *                                     a within-batch tally, not the whole-population in-flight total.
     * @param int         $excludedHosts   The total excluded-host entries across the job's candidates.
     * @param int         $suppressed      The candidates skipped by the negative-memory re-search policy
     *                                     (a fresh, same-signature genuine miss); 0 when overridden.
     */
    public function __construct(
        public ?string $jobId,
        public int $candidates,
        public int $skippedInflight,
        public int $excludedHosts,
        public int $suppressed = 0,
    ) {
    }
}
