<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use MagicSunday\ObituaryMatcher\Support\FinderRequest;

/**
 * Boundary between the orchestration services ({@see \MagicSunday\ObituaryMatcher\Webtrees\EnqueueService}/{@see \MagicSunday\ObituaryMatcher\Webtrees\DrainService})
 * and the external finder job backend. Currently REST-only in production ({@see RestJobTransport});
 * retained as the service seam and the test double point so the services stay HTTP/ledger-free: they
 * submit a request, pull completed/failed outcomes, finalise each one, and ask the transport for the
 * enqueue-side in-flight set and the drain-summary stale tally.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface JobTransport
{
    /**
     * Submits a finder request for processing and returns the job identifier it was filed under.
     *
     * @param FinderRequest $request The request to submit.
     *
     * @return string The submitted job's identifier.
     */
    public function submit(FinderRequest $request): string;

    /**
     * Yields the outcome of every completed job: a {@see CompletedJob} carrying the decoded notices
     * for a job that produced a result, or a {@see FailedJob} carrying a reason category for a job
     * whose per-job read failed. A yielded job is owned by this caller and MUST be finalised via
     * {@see markIngested}, {@see markFailed} or {@see release} so the transport can retire it from the
     * pending set.
     *
     * @return iterable<CompletedJob|FailedJob> The per-job outcomes.
     */
    public function fetchCompleted(): iterable;

    /**
     * Finalises a job as successfully ingested, recording the per-metric ingest counts and warnings.
     *
     * @param string             $jobId    The job identifier to finalise.
     * @param array<string, int> $counts   The per-metric ingest counts (noticesRead, candidatesFound, matchesStored).
     * @param list<string>       $warnings The non-fatal warnings to record (empty when there are none).
     *
     * @return void
     */
    public function markIngested(string $jobId, array $counts, array $warnings = []): void;

    /**
     * Finalises a job as failed, recording the snake_case reason category and any warnings.
     *
     * @param string       $jobId          The job identifier to fail.
     * @param string       $reasonCategory The snake_case category classifying the failure.
     * @param list<string> $warnings       The non-fatal warnings to record (empty when there are none).
     *
     * @return void
     */
    public function markFailed(string $jobId, string $reasonCategory, array $warnings = []): void;

    /**
     * Releases a claimed job back to the completed pool so a later drain re-processes it (a mid-ingest
     * crash or a tree-scoped drain stepping over a foreign job).
     *
     * @param string $jobId The job identifier to release.
     *
     * @return void
     */
    public function release(string $jobId): void;

    /**
     * Yields each in-flight job's request as a narrowed `{treeId, requestedPersonIds}` shape, the
     * source the enqueue producer dedups against by person id. Best-effort: a poison/unreadable
     * in-flight request is skipped, never fatal.
     *
     * @return iterable<array{treeId: int, requestedPersonIds: list<string>}> The in-flight requests.
     */
    public function inFlightRequests(): iterable;

    /**
     * The number of jobs left stranded mid-ingest by an earlier run (the drain summary's stale tally).
     * The REST transport returns 0 — its pending jobs stay pollable in the ledger and are never
     * "claimed" into an intermediate state, so there is no stuck-in-ingesting state to count; the count
     * stays on the seam for a transport that DOES claim jobs and could strand one on a mid-ingest crash.
     *
     * @return int The stale job count.
     */
    public function staleCount(): int;
}
