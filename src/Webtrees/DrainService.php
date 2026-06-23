<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use DomainException;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function is_dir;
use function preg_match;
use function scandir;
use function sort;
use function str_starts_with;

use const SCANDIR_SORT_NONE;
use const SORT_NATURAL;

/**
 * The Phase-2e orchestration boundary: it drains finished feeder jobs (done state) into the per-tree
 * match stores. For every discovered done job it CLAIMS the job first (an atomic done → ingesting
 * rename, so at most one drain process wins it), then reads the CLAIMED request, resolves the target
 * tree, rebuilds the requested candidates and hands the claimed response to the store-agnostic
 * {@see IngestService::ingest()} against the tree-scoped store. Each transition uses the VALIDATED
 * directory job id, never the untrusted JSON job id.
 *
 * The class lives in the {@see \MagicSunday\ObituaryMatcher\Webtrees} adapter layer because it
 * orchestrates {@see TreeService}, {@see CandidateRepository} and {@see MatchStoreFactory}, all of
 * which speak the `Fisharebest\Webtrees\*` runtime; the pure engine ({@see IngestService} and below)
 * stays webtrees-free and is injected.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class DrainService
{
    /**
     * @var string Regular expression a discovered done-directory entry must match to be a claimable
     *             job. It mirrors {@see QueuePaths}'s own path-traversal guard, so a hostile or
     *             foreign directory name is skipped before any claim is attempted; the path builders
     *             remain the authoritative validators on every transition.
     */
    private const string JOB_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param QueuePaths          $paths       The queue path builder used for discovery and roots.
     * @param QueueClient         $client      The queue state-machine driver (claim/finalize/release).
     * @param FeederRequestReader $reader      The validating reader for the claimed request.json.
     * @param CandidateRepository $repository  The repository that rebuilds the requested candidates.
     * @param IngestService       $ingest      The store-agnostic enriched ingest pipeline.
     * @param TreeService         $treeService The webtrees tree lookup (throws on an unknown id).
     */
    public function __construct(
        private readonly QueuePaths $paths,
        private readonly QueueClient $client,
        private readonly FeederRequestReader $reader,
        private readonly CandidateRepository $repository,
        private readonly IngestService $ingest,
        private readonly TreeService $treeService,
    ) {
    }

    /**
     * Drains up to $limit oldest-first done jobs into their per-tree match stores. When $onlyTreeId
     * is non-null only jobs for that tree are ingested; a job for any other tree is released back to
     * the done state (so a tree-scoped drain leaves foreign jobs untouched for another run). After
     * the run the still-ingesting directory is counted into the summary's stale tally — a crashed or
     * concurrently-claimed ingest. This slice does NOT auto-reclaim such a job: it is only counted
     * and reported, and recovery is manual (the operator moves the job back to done). Automatic
     * reclaim is a documented future/optional hardening.
     *
     * @param int|null $onlyTreeId The single tree to ingest, or null to ingest every tree.
     * @param int      $limit      The maximum number of done jobs to process this run.
     *
     * @return DrainSummary The aggregated per-job tallies of this run.
     */
    public function drain(?int $onlyTreeId, int $limit): DrainSummary
    {
        $ingested = 0;
        $skipped  = 0;
        $failed   = 0;
        $stored   = 0;

        foreach ($this->discover($limit) as $jobId) {
            // The claim is the synchronisation point: a job another drain already claimed (or a job
            // that vanished between discovery and now) returns false and is simply skipped.
            if (!$this->client->claimForIngest($jobId)) {
                continue;
            }

            try {
                $request = $this->reader->read($jobId, JobState::Ingesting);
            } catch (ResponseValidationException) {
                // A corrupt or hand-edited request is not retryable: park the claimed job in the
                // failed-ingest state and move on rather than aborting the whole drain.
                $this->client->markFailedIngest($jobId, 'schema_invalid');
                ++$failed;

                continue;
            } catch (RuntimeException) {
                // ResponseValidationException (a RuntimeException subclass) is matched above, so this
                // arm catches a PLAIN RuntimeException: an IO/system failure from readJsonCapped() (a
                // symlink, an unreadable, an oversize or a torn request.json). Isolate it the same
                // way — park the claimed job in failed-ingest and move on rather than aborting the
                // whole drain. Order is REQUIRED: the specific validation type must stay first.
                $this->client->markFailedIngest($jobId, 'request_failed');
                ++$failed;

                continue;
            }

            $treeId = $request['treeId'];

            try {
                $tree = $this->treeService->find($treeId);
            } catch (DomainException) {
                $this->client->markFailedIngest($jobId, 'tree_unknown');
                ++$failed;

                continue;
            }

            if (
                ($onlyTreeId !== null)
                && ($treeId !== $onlyTreeId)
            ) {
                // A tree-scoped drain leaves a foreign job for another run: release it back to done.
                // A failed release (it vanished or was re-claimed) is the only failure path here.
                if ($this->client->releaseIngesting($jobId)) {
                    ++$skipped;
                } else {
                    $this->client->markFailedIngest($jobId, 'release_failed');
                    ++$failed;
                }

                continue;
            }

            // The store is built PER JOB so a multi-tree drain persists each job to its own
            // tree-scoped store; the ingest stays store-agnostic.
            $store = $this->storeForTree($tree);

            $candidatesById = $this->repository->findByXrefs($tree, $request['requestedPersonIds']);

            try {
                // The ingest reads the untrusted response.json via the ResponseReader, which throws
                // on a corrupt or hand-edited response. Isolate the failure per job: park the claimed
                // job in failed-ingest and move on rather than aborting the whole drain. markIngested
                // and the ingested tally stay INSIDE the try, applied only on a successful ingest.
                $result = $this->ingest->ingest(
                    $jobId,
                    $request['requestedPersonIds'],
                    $candidatesById,
                    $store,
                );

                $stored += $result->matchesStored;

                $this->client->markIngested(
                    $jobId,
                    [
                        'noticesRead'     => $result->noticesRead,
                        'candidatesFound' => $result->candidatesFound,
                        'matchesStored'   => $result->matchesStored,
                    ],
                    $result->warnings,
                );

                ++$ingested;
            } catch (ResponseValidationException) {
                // A corrupt or hand-edited response is not retryable: park the claimed job in the
                // failed-ingest state and move on rather than aborting the whole drain.
                $this->client->markFailedIngest($jobId, 'response_invalid');
                ++$failed;

                continue;
            } catch (Throwable) {
                // Any other ingest failure is isolated the same way: the one bad job is parked and
                // the drain continues with the next, so a single failure never strands the batch.
                $this->client->markFailedIngest($jobId, 'ingest_failed');
                ++$failed;

                continue;
            }
        }

        return new DrainSummary($ingested, $skipped, $failed, $stored, $this->countStale());
    }

    /**
     * Builds the tree-scoped match store the ingest persists into. A seam (mirroring
     * {@see ReviewScreenHandler::storeForTree()}) so a test can redirect the per-tree store to an
     * isolated directory rather than the live data dir.
     *
     * @param Tree $tree The tree whose match store is requested.
     *
     * @return MatchStore The tree-scoped match store.
     */
    protected function storeForTree(Tree $tree): MatchStore
    {
        return MatchStoreFactory::forTree($tree);
    }

    /**
     * Discovers the claimable done jobs, oldest-first and capped at $limit. A reserved temporary
     * directory (the {@see QueueClient} enqueue prefix) and any entry failing the job-id pattern are
     * skipped before a claim is ever attempted.
     *
     * @param int $limit The maximum number of job ids to return.
     *
     * @return list<string> The discovered job ids, oldest-first, capped at $limit.
     */
    private function discover(int $limit): array
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
            static fn (string $entry): bool => ($entry !== '.')
                && ($entry !== '..')
                && !str_starts_with($entry, '.tmp-')
                && (preg_match(self::JOB_ID_PATTERN, $entry) === 1),
        );

        // Oldest-first by monotonically increasing job id: the feeder mints monotonically increasing
        // ids, so a NATURAL name sort is an oldest-first ordering without reading the wall clock. A
        // natural (not lexicographic) sort keeps unpadded ids ordered correctly (job-2 before job-10),
        // which also governs which jobs survive the array_slice cap below.
        $jobIds = array_values($jobIds);
        sort($jobIds, SORT_NATURAL);

        return array_slice($jobIds, 0, $limit);
    }

    /**
     * Counts the directories still sitting in the ingesting state after the drain finished. Each is a
     * job whose ingest never completed (a crash) or one a concurrent drain is mid-claim on. This
     * slice does NOT auto-reclaim them: the count is surfaced as the summary's stale tally and
     * reported, but recovery is manual (the operator moves the job back to done). Automatic reclaim
     * is a documented future/optional hardening, not done here.
     *
     * @return int The number of still-ingesting job directories.
     */
    private function countStale(): int
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
            static fn (string $entry): bool => ($entry !== '.')
                && ($entry !== '..')
                && !str_starts_with($entry, '.tmp-')
                && (preg_match(self::JOB_ID_PATTERN, $entry) === 1),
        );

        return count($jobs);
    }
}
