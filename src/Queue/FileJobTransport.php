<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use RuntimeException;

use function array_filter;
use function array_values;
use function count;
use function is_dir;
use function scandir;
use function sort;

use const SCANDIR_SORT_NONE;
use const SORT_NATURAL;

/**
 * The file-drop {@see JobTransport}: it carries feeder jobs on the local filesystem queue, mapping the
 * transport-neutral lifecycle onto the {@see QueueClient} state machine and encapsulating the
 * discover + claim + request-read + response-read the {@see \MagicSunday\ObituaryMatcher\Webtrees\DrainService}
 * used to perform inline. Each completed done job is CLAIMED (an atomic done → ingesting rename, so at
 * most one drain wins it) before it is yielded, and the claimed request/response are read with the
 * VALIDATED directory job id — never the untrusted JSON job id.
 *
 * The per-job read faults are mapped to the SAME reason categories the file path's status.json has
 * always persisted, so the categorisation stays byte-for-byte unchanged across the transport seam:
 * a request validation reject → `schema_invalid`, a request IO fault → `request_failed`, a response
 * validation reject → `response_invalid`, a response IO fault (a torn response.json) → `ingest_failed`.
 *
 * The class is pure (it lives in the {@see \MagicSunday\ObituaryMatcher\Queue} layer): it depends only
 * on {@see QueueClient}, {@see ResponseReader}, {@see FeederRequestReader}, {@see QueuePaths} and its own
 * value objects, so it stays webtrees-free and the {@see DrainService}/{@see EnqueueService} adapters
 * inject it through the {@see JobTransport} seam.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FileJobTransport implements JobTransport
{
    /**
     * Constructor.
     *
     * @param QueueClient         $client         The queue state-machine driver (enqueue/claim/finalize/release).
     * @param ResponseReader      $responseReader The validating reader for the claimed response.json.
     * @param FeederRequestReader $requestReader  The validating reader for the claimed request.json.
     * @param QueuePaths          $paths          The queue path builder used for discovery and state roots.
     */
    public function __construct(
        private QueueClient $client,
        private ResponseReader $responseReader,
        private FeederRequestReader $requestReader,
        private QueuePaths $paths,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param FeederRequest $request The request to enqueue; its jobId becomes the job directory name.
     *
     * @return string The enqueued job's identifier.
     */
    public function submit(FeederRequest $request): string
    {
        return $this->client->enqueue($request);
    }

    /**
     * {@inheritDoc}
     *
     * Discovers the claimable done jobs oldest-first, claims each (a lost/vanished claim is silently
     * skipped), then reads the claimed request and response. A per-job read fault yields a
     * {@see FailedJob} under the preserved file-path reason category rather than aborting the scan; a
     * fully readable job yields a {@see CompletedJob}. Specific validation types are caught BEFORE the
     * plain {@see RuntimeException} so an IO fault never masquerades as a validation reject.
     *
     * @return iterable<CompletedJob|FailedJob> The per-job outcomes.
     */
    public function fetchCompleted(): iterable
    {
        foreach ($this->discoverDone() as $jobId) {
            // The claim is the synchronisation point: a job another drain already claimed (or a job
            // that vanished between discovery and now) returns false and is simply skipped.
            if (!$this->client->claimForIngest($jobId)) {
                continue;
            }

            try {
                $request = $this->requestReader->read($jobId, JobState::Ingesting);
            } catch (ResponseValidationException) {
                // A corrupt or hand-edited request is not retryable. Order is REQUIRED: the specific
                // validation type must stay before the plain RuntimeException arm.
                yield new FailedJob($jobId, 0, [], 'schema_invalid');

                continue;
            } catch (RuntimeException) {
                // A plain RuntimeException is an IO/system failure from readJsonCapped() (a symlink, an
                // unreadable, an oversize or a torn request.json) — isolated under the request category.
                yield new FailedJob($jobId, 0, [], 'request_failed');

                continue;
            }

            try {
                $notices = $this->responseReader->read(
                    $jobId,
                    $request['requestedPersonIds'],
                    JobState::Ingesting,
                );
            } catch (ResponseValidationException) {
                // A corrupt or hand-edited response is not retryable. Order is REQUIRED: the specific
                // validation type must stay before the plain RuntimeException arm.
                yield new FailedJob($jobId, $request['treeId'], $request['requestedPersonIds'], 'response_invalid');

                continue;
            } catch (RuntimeException) {
                // A plain RuntimeException is a response IO/system failure (a torn response.json):
                // preserve the file path's original `ingest_failed` category for a RESPONSE-read fault,
                // NEVER `request_failed`.
                yield new FailedJob($jobId, $request['treeId'], $request['requestedPersonIds'], 'ingest_failed');

                continue;
            }

            yield new CompletedJob($jobId, $request['treeId'], $request['requestedPersonIds'], $notices);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param string             $jobId    The job identifier to finalise.
     * @param array<string, int> $counts   The per-metric ingest counts.
     * @param list<string>       $warnings The non-fatal warnings to record.
     *
     * @return void
     */
    public function markIngested(string $jobId, array $counts, array $warnings = []): void
    {
        $this->client->markIngested($jobId, $counts, $warnings);
    }

    /**
     * {@inheritDoc}
     *
     * @param string       $jobId          The job identifier to fail.
     * @param string       $reasonCategory The snake_case category classifying the failure.
     * @param list<string> $warnings       The non-fatal warnings to record.
     *
     * @return void
     */
    public function markFailed(string $jobId, string $reasonCategory, array $warnings = []): void
    {
        $this->client->markFailedIngest($jobId, $reasonCategory, $warnings);
    }

    /**
     * {@inheritDoc}
     *
     * The boolean return of {@see QueueClient::releaseIngesting()} (whether this caller won the release
     * race) is ignored: the drain re-processes a still-claimed job on a later run regardless.
     *
     * @param string $jobId The job identifier to release back to the done state.
     *
     * @return void
     */
    public function release(string $jobId): void
    {
        $this->client->releaseIngesting($jobId);
    }

    /**
     * {@inheritDoc}
     *
     * Scans every in-flight state root and yields each job's narrowed request. A poison/unreadable
     * in-flight request (the reader throws a validation, IO or path-guard exception) is skipped, never
     * fatal, so one corrupt foreign job cannot block the producer.
     *
     * @return iterable<array{treeId: int, requestedPersonIds: list<string>}> The in-flight requests.
     */
    public function inFlightRequests(): iterable
    {
        foreach ($this->inFlightStates() as $state) {
            $root = $this->paths->stateRoot($state->value);

            if (!is_dir($root)) {
                continue;
            }

            $entries = scandir($root, SCANDIR_SORT_NONE);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!$this->paths->isJobDirectoryName($entry)) {
                    continue;
                }

                try {
                    $request = $this->requestReader->read($entry, $state);
                } catch (RuntimeException|InvalidArgumentException) {
                    // Warn-and-ignore: a corrupt/foreign/path-hostile in-flight job must never block the
                    // producer. A schema-invalid request surfaces as a ResponseValidationException and a
                    // broken-JSON / IO failure as a plain RuntimeException — both are RuntimeException
                    // subclasses, so the one arm covers them; the path-traversal guard throws
                    // InvalidArgumentException, which is NOT a RuntimeException, so it needs its own arm.
                    continue;
                }

                yield $request;
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * Counts the directories still sitting in the ingesting state — each a job whose ingest never
     * completed (a crash) or one a concurrent drain is mid-claim on. A stray non-job entry (a name
     * failing the job-id pattern) is filtered out, so the operator-facing tally is never inflated by a
     * foreign filesystem artefact.
     *
     * @return int The number of still-ingesting job directories.
     */
    public function staleCount(): int
    {
        $ingestingRoot = $this->paths->stateRoot(JobState::Ingesting->value);

        if (!is_dir($ingestingRoot)) {
            return 0;
        }

        $entries = scandir($ingestingRoot, SCANDIR_SORT_NONE);

        if ($entries === false) {
            return 0;
        }

        $jobs = array_filter(
            $entries,
            $this->paths->isJobDirectoryName(...),
        );

        return count($jobs);
    }

    /**
     * Discovers the claimable done jobs, oldest-first. A reserved temporary directory (the
     * {@see QueueClient} enqueue prefix) and any entry failing the job-id pattern are skipped before a
     * claim is ever attempted.
     *
     * The producer mints time-prefixed ids (a fixed-width UTC timestamp prefix), so a NATURAL name sort
     * is an oldest-first ordering without reading the wall clock. Same-second order is unspecified (a
     * random tiebreak) and the drain does not depend on it — each job ingests independently. A natural
     * (not lexicographic) sort keeps unpadded ids ordered correctly.
     *
     * @return list<string> The discovered job ids, oldest-first.
     */
    private function discoverDone(): array
    {
        $doneRoot = $this->paths->stateRoot(JobState::Done->value);

        if (!is_dir($doneRoot)) {
            return [];
        }

        $entries = scandir($doneRoot, SCANDIR_SORT_NONE);

        if ($entries === false) {
            return [];
        }

        $jobIds = array_filter(
            $entries,
            $this->paths->isJobDirectoryName(...),
        );

        $jobIds = array_values($jobIds);
        sort($jobIds, SORT_NATURAL);

        return $jobIds;
    }

    /**
     * The states in which a job's request counts as in-flight for the enqueue-side dedup scan. Done =
     * the feeder produced a result not yet persisted; Ingesting = the drain is mid-transition. A method
     * (not a class constant) so the enum-case list never relies on constant-expression edge cases.
     *
     * @return list<JobState> The in-flight states.
     */
    private function inFlightStates(): array
    {
        return [
            JobState::Queued,
            JobState::Running,
            JobState::Done,
            JobState::Ingesting,
        ];
    }
}
