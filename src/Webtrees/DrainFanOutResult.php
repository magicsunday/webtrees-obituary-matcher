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
 * The aggregated tally of one multi-finder drain fan-out (§5.2f): the summed per-finder ingested /
 * skipped / failed / stored / stale counts across every finder drained this run. A single-finder run
 * aggregates exactly one {@see DrainSummary}, so the result mirrors that summary. Extracting the
 * summation here keeps the arithmetic testable rather than inlined in the CLI adapter, and exposes
 * {@see self::hasFailure()} so the adapter's exit code reflects a failure at ANY finder.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DrainFanOutResult
{
    /**
     * Constructor.
     *
     * @param int $ingested The total jobs ingested across all finders.
     * @param int $skipped  The total jobs skipped (foreign-tree releases) across all finders.
     * @param int $failed   The total jobs parked as failed across all finders.
     * @param int $stored   The total match rows stored across all finders.
     * @param int $stale    The total stale claims reported across all finders.
     */
    public function __construct(
        public int $ingested,
        public int $skipped,
        public int $failed,
        public int $stored,
        public int $stale,
    ) {
    }

    /**
     * Aggregates the per-finder drain summaries of one fan-out run into a single tally by summing each
     * count across the finders.
     *
     * @param list<DrainSummary> $summaries The per-finder summaries, in fan-out order.
     *
     * @return self The aggregated fan-out result.
     */
    public static function fromSummaries(array $summaries): self
    {
        $ingested = 0;
        $skipped  = 0;
        $failed   = 0;
        $stored   = 0;
        $stale    = 0;

        foreach ($summaries as $summary) {
            $ingested += $summary->ingested;
            $skipped  += $summary->skipped;
            $failed   += $summary->failed;
            $stored   += $summary->stored;
            $stale    += $summary->stale;
        }

        return new self($ingested, $skipped, $failed, $stored, $stale);
    }

    /**
     * Whether any finder had a failed ingest this run — the CLI adapter maps this to a non-zero exit.
     *
     * @return bool True when at least one job was parked as failed at any finder.
     */
    public function hasFailure(): bool
    {
        return $this->failed > 0;
    }
}
