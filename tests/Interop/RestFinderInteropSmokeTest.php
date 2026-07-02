<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Interop;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Support\CandidateQuery;
use MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use MagicSunday\ObituaryMatcher\Webtrees\JobTransportFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

use function array_keys;
use function count;
use function getenv;
use function is_string;
use function sprintf;
use function trim;
use function usleep;

/**
 * An OPTIONAL, availability-gated REST finder interoperability smoke (#79). It submits ONE minimal finder
 * job to a REAL finder service over HTTP and drives the matcher's own transport + response validator end
 * to end, asserting the finder's HTTP response narrows and validates congruently into
 * {@see DeathNoticeRecord}s.
 *
 * DELIBERATELY OUTSIDE the matcher's normal suites: it lives in its own `interop` PHPUnit testsuite (see
 * `phpunit.xml`), is NOT part of `composer ci:test`, and is NOT wired into the public repo's required CI —
 * a live private-finder harness would couple this repo to private finder details. For the matcher's own
 * CI the scripted PSR-18 stub + the schema / {@see \MagicSunday\ObituaryMatcher\Queue\ResponseValidator}
 * gates remain the contract coverage; this smoke is the cross-service congruence check, run only where a
 * finder is reachable (a maintainer's environment, or the finder side).
 *
 * It SKIPS unless the finder base URL is provided via the `OBITUARY_FINDER_SMOKE_BASE_URL` environment
 * variable (with an optional `OBITUARY_FINDER_SMOKE_TOKEN` bearer token), so it never fails or runs by
 * accident. See the README (Development → optional REST finder interoperability smoke).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[Group('interop')]
#[CoversNothing]
final class RestFinderInteropSmokeTest extends TempDirTestCase
{
    /**
     * The environment variable naming the reachable finder base URL; absent → the smoke skips.
     */
    private const string ENV_BASE_URL = 'OBITUARY_FINDER_SMOKE_BASE_URL';

    /**
     * The environment variable carrying the optional finder bearer token.
     */
    private const string ENV_TOKEN = 'OBITUARY_FINDER_SMOKE_TOKEN';

    /**
     * The maximum number of drain polls before giving up on the finder returning the job.
     */
    private const int MAX_POLLS = 30;

    /**
     * The delay between drain polls, in microseconds (1 second).
     */
    private const int POLL_DELAY_MICROSECONDS = 1_000_000;

    /**
     * Submits one job to the live finder and asserts the response round-trips + validates into notices.
     *
     * @return void
     */
    #[Test]
    public function aRealFinderResponseValidatesAndNarrowsCongruently(): void
    {
        $baseUrl = getenv(self::ENV_BASE_URL);

        if (!is_string($baseUrl) || (trim($baseUrl) === '')) {
            self::markTestSkipped(sprintf(
                'Set %s (and optionally %s) to a reachable finder to run the REST interop smoke.',
                self::ENV_BASE_URL,
                self::ENV_TOKEN,
            ));
        }

        $tokenRaw = getenv(self::ENV_TOKEN);
        $token    = is_string($tokenRaw) && (trim($tokenRaw) !== '') ? $tokenRaw : null;

        // The per-test temp dir (TempDirTestCase, removed recursively in tearDown) is the transport's REST
        // in-flight ledger root — the transport writes pending/claimed entries and a lock file under it.
        $connection = FinderConnection::rest(trim($baseUrl), $token);
        $transport  = JobTransportFactory::create($connection, $this->tmp);

        $jobId   = JobId::mint(new DateTimeImmutable());
        $request = new FinderRequest(
            $jobId,
            new DateTimeImmutable(),
            'de-DE',
            [
                new FinderCandidateRequest(
                    'I1',
                    new PersonName(['Erika'], null, 'Mustermann', null),
                    [new CandidateQuery('Erika Mustermann Traueranzeige', 1, 'erika-mustermann')],
                ),
            ],
            1,
        );

        $transport->submit($request);

        $outcome = $this->drainUntilJobReturns($transport, $jobId);

        try {
            // The transport yields a CompletedJob ONLY after the finder's HTTP response passed the runtime
            // ResponseValidator gate — its schema-version, job-ownership and per-person shape checks (NOT a
            // full JSON-Schema conformance pass; that stays a design-time contract); a validation failure
            // yields a FailedJob instead. So a CompletedJob for our jobId IS the congruent end-to-end
            // proof: submit → GET → validate → narrow succeeded. Surface a returned FailedJob's category so
            // an interop mismatch is diagnosable rather than a bare timeout.
            $failureDetail = $outcome instanceof FailedJob
                ? sprintf(' The finder returned a FailedJob (%s).', $outcome->reasonCategory->value)
                : '';

            self::assertInstanceOf(
                CompletedJob::class,
                $outcome,
                'The finder did not return a ResponseValidator-accepted response for the submitted job'
                . ' within the timeout.'
                . $failureDetail,
            );
            self::assertSame($jobId, $outcome->jobId);

            // Every narrowed notice list is keyed by the one person we requested — the job-ownership
            // boundary the validator enforces, i.e. the congruent narrowing (the values are already typed
            // DeathNoticeRecords by the validator's contract). Set-membership only: the finder's match set
            // is non-deterministic, so never assert a count or a specific notice identity.
            foreach (array_keys($outcome->notices) as $personId) {
                self::assertSame('I1', $personId);
            }

            // Count the ACTUAL narrowed records. A bare non-empty check on the outer map would still pass
            // on `['I1' => []]` (a person key mapping to an empty list — which the validator permits),
            // vacating the "narrows congruently INTO DeathNoticeRecords" proof while staying green, so
            // assert on the flattened record total. Still cardinality-only (>= 1): the match set is
            // non-deterministic so no exact count or notice identity is pinned.
            self::assertGreaterThan(
                0,
                $this->flattenedNoticeCount($outcome),
                'The finder returned a completed job but no narrowed DeathNoticeRecords to validate.',
            );
        } catch (Throwable $assertionFailure) {
            // The assertions failed (or the drain timed out). Retire the yielded job best-effort so the
            // failed run strands no local claim, but NEVER let a cleanup error mask the real failure —
            // re-throw the original.
            try {
                $this->finaliseDrainedJob($transport, $outcome);
            } catch (Throwable) {
                // Intentionally ignored on the failure path — cleanup must not replace the primary failure.
            }

            throw $assertionFailure;
        }

        // The assertions passed: finalise normally. Unlike the failure path, a finalisation failure on an
        // otherwise-successful run is a real defect (an un-retired claim / an undeleted remote job) and
        // MUST surface as a test failure rather than be swallowed.
        $this->finaliseDrainedJob($transport, $outcome);
    }

    /**
     * Retires a drained job through the transport contract so no yielded job is left un-finalised.
     *
     * A CompletedJob is ingested (clears the local claim and best-effort deletes the remote job); a
     * FailedJob is marked failed (clears the local claim; the remote failed job is retained by design so
     * its result stays recoverable); a null outcome (nothing came back) needs no finalisation.
     *
     * @param JobTransport                $transport The wired REST transport.
     * @param CompletedJob|FailedJob|null $outcome   The drained job to retire, or null when none returned.
     *
     * @return void
     */
    private function finaliseDrainedJob(JobTransport $transport, CompletedJob|FailedJob|null $outcome): void
    {
        if ($outcome instanceof CompletedJob) {
            $transport->markIngested($outcome->jobId, [
                'noticesRead'     => $this->flattenedNoticeCount($outcome),
                'candidatesFound' => count($outcome->notices),
                'matchesStored'   => 0,
            ]);

            return;
        }

        if ($outcome instanceof FailedJob) {
            $transport->markFailed($outcome->jobId, $outcome->reasonCategory);
        }
    }

    /**
     * Sums the narrowed DeathNoticeRecords a completed job carries across all requested persons.
     *
     * @param CompletedJob $outcome The completed job whose per-person notice lists are summed.
     *
     * @return int The flattened record total.
     */
    private function flattenedNoticeCount(CompletedJob $outcome): int
    {
        $total = 0;

        foreach ($outcome->notices as $personNotices) {
            $total += count($personNotices);
        }

        return $total;
    }

    /**
     * Polls the transport's drain until the submitted job comes back (completed or failed), or the poll
     * budget is exhausted. Returns the yielded job for the given id, or null when it never returned.
     *
     * @param JobTransport $transport The wired REST transport.
     * @param string       $jobId     The submitted job id to wait for.
     *
     * @return CompletedJob|FailedJob|null The returned job, or null when it never came back.
     */
    private function drainUntilJobReturns(
        JobTransport $transport,
        string $jobId,
    ): CompletedJob|FailedJob|null {
        for ($poll = 0; $poll < self::MAX_POLLS; ++$poll) {
            foreach ($transport->fetchCompleted() as $job) {
                if ($job->jobId === $jobId) {
                    return $job;
                }

                // A yielded job we are not waiting for (leftover or foreign ledger state): the drain marks
                // every yielded job handled, so hand its claim back to the pending pool rather than strand
                // it. Cannot occur for this single-job, fresh-ledger smoke, but keeps the drain leak-free.
                $transport->release($job->jobId);
            }

            // Sleep BETWEEN polls only, never after the last one (no dead trailing second).
            if ($poll < (self::MAX_POLLS - 1)) {
                usleep(self::POLL_DELAY_MICROSECONDS);
            }
        }

        return null;
    }
}
