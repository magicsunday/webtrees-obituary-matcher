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
 * The outcome of one {@see DrainService::drain()} run, aggregated across every claimed job. The four
 * per-job tallies are mutually exclusive (a job is counted in exactly one of ingested/skipped/failed)
 * and $stored is the cumulative number of pending suggestions persisted across all ingested jobs;
 * $stale is the number of jobs still sitting in the ingesting state AFTER the drain finished — a
 * crashed or concurrently-claimed ingest the next run will re-process.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DrainSummary
{
    /**
     * Constructor.
     *
     * @param int $ingested The number of jobs fully ingested and finalised to the ingested state.
     * @param int $skipped  The number of jobs released back to done (a tree-filter mismatch).
     * @param int $failed   The number of jobs moved to failed-ingest (schema/tree/release failure).
     * @param int $stored   The cumulative number of pending suggestions persisted across all jobs.
     * @param int $stale    The number of jobs left in the ingesting state after the drain finished.
     */
    public function __construct(
        public int $ingested,
        public int $skipped,
        public int $failed,
        public int $stored,
        public int $stale,
    ) {
    }
}
