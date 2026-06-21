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
 * An immutable snapshot of a job's status. The {@see JobState} is always present; the remaining
 * fields are populated only once the corresponding transition has written them and are null
 * otherwise (for example a queued or running job carries no finishedAt, error or counts).
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
     * @param JobState    $state      The job's current state.
     * @param string|null $startedAt  The ISO-8601 instant the job was claimed, or null if unknown.
     * @param string|null $finishedAt The ISO-8601 instant the job finished, or null if not finished.
     * @param string|null $error      The failure message for a failed job, or null otherwise.
     * @param int|null    $counts     The number of produced results for a done job, or null otherwise.
     */
    public function __construct(
        public JobState $state,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $error,
        public ?int $counts,
    ) {
    }
}
