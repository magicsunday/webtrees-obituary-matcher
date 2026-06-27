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
 * The tally deltas a SINGLE completed job contributes to a {@see DrainSummary}, so
 * {@see DrainService::drain()} can keep one flat loop with a single $limit break instead of mutating
 * four counters by reference across a deeply nested branch. Exactly one of the three counters is 1 per
 * job; `stored` carries the persisted-row delta of an ingested job (0 otherwise).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DrainOutcome
{
    /**
     * Constructor.
     *
     * @param int $ingested The ingested-job delta (0 or 1).
     * @param int $skipped  The skipped-job delta (0 or 1).
     * @param int $failed   The failed-job delta (0 or 1).
     * @param int $stored   The persisted-row delta this job contributed.
     */
    private function __construct(
        public int $ingested,
        public int $skipped,
        public int $failed,
        public int $stored,
    ) {
    }

    /**
     * A successfully ingested job that persisted $stored rows.
     *
     * @param int $stored The number of rows the ingest persisted.
     *
     * @return self
     */
    public static function ingested(int $stored): self
    {
        return new self(1, 0, 0, $stored);
    }

    /**
     * A job released as foreign to a tree-scoped drain.
     *
     * @return self
     */
    public static function skipped(): self
    {
        return new self(0, 1, 0, 0);
    }

    /**
     * A job parked failed or released after a mid-ingest throw.
     *
     * @return self
     */
    public static function failed(): self
    {
        return new self(0, 0, 1, 0);
    }
}
