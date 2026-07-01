<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;

use function in_array;

/**
 * A recording {@see JobTransport} test double: it replaces the on-disk file-drop queue in the enqueue
 * and drain integration harnesses so the services can be driven transport-neutrally. Seeded completed
 * outcomes and in-flight requests are yielded verbatim, while every finalisation the drain performs
 * (ingest / fail / release) and every enqueue submission is captured in a public list for assertions.
 * The stale tally is a fixed seam so a drain test can pin that the transport's count is surfaced in the
 * summary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RecordingJobTransport implements JobTransport
{
    /**
     * @var list<FeederRequest> The requests the producer submitted, in order.
     */
    public array $submitted = [];

    /**
     * @var list<array{jobId: string, counts: array<string, int>, warnings: list<string>}> The ingest finalisations.
     */
    public array $ingested = [];

    /**
     * @var list<array{jobId: string, reasonCategory: string, warnings: list<string>}> The failure finalisations.
     */
    public array $failed = [];

    /**
     * @var list<string> The job ids released back to the completed pool, in order.
     */
    public array $released = [];

    /**
     * @param list<CompletedJob|FailedJob>                               $completed  The seeded completed outcomes the drain pulls.
     * @param list<array{treeId: int, requestedPersonIds: list<string>}> $inFlight   The seeded in-flight requests the producer dedups against.
     * @param int                                                        $staleCount The fixed stale tally the drain summary surfaces.
     */
    public function __construct(
        private readonly array $completed = [],
        private readonly array $inFlight = [],
        private readonly int $staleCount = 0,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function submit(FeederRequest $request): string
    {
        $this->submitted[] = $request;

        return $request->jobId;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchCompleted(): iterable
    {
        yield from $this->completed;
    }

    /**
     * {@inheritDoc}
     */
    public function markIngested(string $jobId, array $counts, array $warnings = []): void
    {
        $this->ingested[] = ['jobId' => $jobId, 'counts' => $counts, 'warnings' => $warnings];
    }

    /**
     * {@inheritDoc}
     */
    public function markFailed(string $jobId, string $reasonCategory, array $warnings = []): void
    {
        $this->failed[] = ['jobId' => $jobId, 'reasonCategory' => $reasonCategory, 'warnings' => $warnings];
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $jobId): void
    {
        $this->released[] = $jobId;
    }

    /**
     * {@inheritDoc}
     */
    public function inFlightRequests(): iterable
    {
        yield from $this->inFlight;
    }

    /**
     * {@inheritDoc}
     */
    public function staleCount(): int
    {
        return $this->staleCount;
    }

    /**
     * Whether the drain finalised the given job as successfully ingested.
     *
     * @param string $jobId The job identifier to check.
     *
     * @return bool True when {@see markIngested()} recorded the job.
     */
    public function wasIngested(string $jobId): bool
    {
        foreach ($this->ingested as $entry) {
            if ($entry['jobId'] === $jobId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the drain released the given job back to the completed pool.
     *
     * @param string $jobId The job identifier to check.
     *
     * @return bool True when {@see release()} recorded the job.
     */
    public function wasReleased(string $jobId): bool
    {
        return in_array($jobId, $this->released, true);
    }

    /**
     * The snake_case reason category the drain parked the given job under, or null when it was never
     * failed.
     *
     * @param string $jobId The job identifier to check.
     *
     * @return string|null The recorded failure category, or null.
     */
    public function failureReason(string $jobId): ?string
    {
        foreach ($this->failed as $entry) {
            if ($entry['jobId'] === $jobId) {
                return $entry['reasonCategory'];
            }
        }

        return null;
    }
}
