<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

/**
 * An immutable snapshot of a job's status. The {@see JobState} is always present; the nullable
 * string fields are populated only once the corresponding transition has written them and are null
 * otherwise (for example a queued or running job carries no finishedAt or error). The {@see $counts}
 * map and {@see $warnings} list are always present but empty for a job that has produced none — the
 * worker writes `counts` as a per-metric map and `warnings` as a list of strings, so a queued,
 * running or failed job simply carries the empty collection rather than null.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class JobStatus
{
    /**
     * Constructor.
     *
     * @param JobState           $state      The job's current state.
     * @param string|null        $startedAt  The ISO-8601 instant the job was claimed, or null if unknown.
     * @param string|null        $finishedAt The ISO-8601 instant the job finished, or null if not finished.
     * @param string|null        $error      The failure message for a failed job, or null otherwise.
     * @param array<string, int> $counts     The worker's per-metric production counts (candidates,
     *                                       queries, notices, skippedNotices, portalErrors) for a done
     *                                       job, or an empty map when the job produced none.
     * @param list<string>       $warnings   The non-fatal warnings the worker recorded, or an empty list.
     */
    public function __construct(
        public JobState $state,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $error,
        public array $counts,
        public array $warnings,
    ) {
    }
}
