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
use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use Throwable;

/**
 * The Phase-2e orchestration boundary: it drains finished feeder jobs into the per-tree match stores.
 * The transport ({@see JobTransport}) yields each completed job — a {@see CompletedJob} carrying its
 * validated notices, or a {@see FailedJob} carrying a per-job read fault category — so this service stays
 * oblivious to where the jobs live (the on-disk file queue or the REST ledger). The file transport yields
 * each job already atomically claimed (`done` → `ingesting`), so overlapping drains never double-process;
 * the REST transport polls its ledger without a claim, so two overlapping drains over the same REST
 * ledger can each process the same job (a known limitation tracked in issue #71 — non-corrupting today
 * because the per-row atomic store is last-writer-wins). For each completed job it resolves the target
 * tree, rebuilds the requested candidates and hands the notices to the store-agnostic
 * {@see IngestService::ingest()} against the tree-scoped store, then finalises the job through the
 * transport (ingested / failed / released).
 *
 * The class lives in the {@see \MagicSunday\ObituaryMatcher\Webtrees} adapter layer because it
 * orchestrates {@see TreeService}, {@see CandidateRepository} and {@see MatchStoreFactory}, all of
 * which speak the `Fisharebest\Webtrees\*` runtime; the pure engine ({@see IngestService} and below)
 * and the transport stay webtrees-free and are injected.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class DrainService
{
    /**
     * Constructor.
     *
     * @param CandidateRepository $repository  The repository that rebuilds the requested candidates.
     * @param IngestService       $ingest      The store-agnostic enriched ingest pipeline.
     * @param TreeService         $treeService The webtrees tree lookup (throws on an unknown id).
     * @param JobTransport        $transport   The transport that yields and finalises completed jobs.
     */
    public function __construct(
        private readonly CandidateRepository $repository,
        private readonly IngestService $ingest,
        private readonly TreeService $treeService,
        private readonly JobTransport $transport,
    ) {
    }

    /**
     * Drains up to $limit oldest-first completed jobs into their per-tree match stores. When
     * $onlyTreeId is non-null only jobs for that tree are ingested; a job for any other tree is
     * released back to the completed pool (so a tree-scoped drain leaves foreign jobs untouched for
     * another run). A job whose ingest throws mid-flight is terminally parked as `ingest_failed` — a
     * deterministically-throwing job must not be released and re-claimed every drain (head-of-line
     * starvation). After the run the transport's stale tally (the
     * file transport's still-ingesting directories; 0 for the REST transport) is reported in the
     * summary; this slice does NOT auto-reclaim such a job (recovery is manual/a future hardening).
     *
     * The $limit bounds the number of CLAIMED jobs processed: the loop breaks after the $limit-th, so
     * the (unbounded) transport iterator is never advanced past it — the file transport therefore never
     * claims a job beyond the cap.
     *
     * @param int|null $onlyTreeId The single tree to ingest, or null to ingest every tree.
     * @param int      $limit      The maximum number of completed jobs to process this run.
     *
     * @return DrainSummary The aggregated per-job tallies of this run.
     */
    public function drain(?int $onlyTreeId, int $limit): DrainSummary
    {
        // Contract symmetry with EnqueueService: a non-positive cap processes nothing (the break-AFTER
        // loop would otherwise still process one job). The stale tally is still reported.
        if ($limit < 1) {
            return new DrainSummary(0, 0, 0, 0, $this->transport->staleCount());
        }

        $ingested = 0;
        $skipped  = 0;
        $failed   = 0;
        $stored   = 0;
        $seen     = 0;

        foreach ($this->transport->fetchCompleted() as $job) {
            ++$seen;

            if ($job instanceof FailedJob) {
                // A per-job read fault the transport already classified: park it under its category.
                $this->parkFailed($job->jobId, $job->reasonCategory);
                ++$failed;
            } else {
                $outcome = $this->ingestCompleted($job, $onlyTreeId);
                $ingested += $outcome->ingested;
                $skipped  += $outcome->skipped;
                $failed   += $outcome->failed;
                $stored   += $outcome->stored;
            }

            // Break AFTER processing so the transport iterator is never advanced past the cap (the file
            // transport claims a job as it is yielded, so an early advance would strand an over-claimed
            // job in the ingesting state).
            if ($seen >= $limit) {
                break;
            }
        }

        return new DrainSummary($ingested, $skipped, $failed, $stored, $this->transport->staleCount());
    }

    /**
     * Ingests a single claimed {@see CompletedJob} and finalises it through the transport, returning the
     * tally deltas this one job contributes. The tree is resolved first (an unknown id is parked as
     * `tree_unknown`); a tree-scoped drain releases a foreign job; otherwise the notices are ingested
     * into the tree-scoped store and the job marked ingested. A throw from the ingest (or its
     * markIngested finalisation) parks the job as `ingest_failed` rather than releasing it.
     *
     * @param CompletedJob $job        The claimed completed job to ingest.
     * @param int|null     $onlyTreeId The single tree to ingest, or null to ingest every tree.
     *
     * @return DrainOutcome The tally deltas this job contributes.
     */
    private function ingestCompleted(CompletedJob $job, ?int $onlyTreeId): DrainOutcome
    {
        try {
            $tree = $this->treeService->find($job->treeId);
        } catch (DomainException) {
            $this->parkFailed($job->jobId, 'tree_unknown');

            return DrainOutcome::failed();
        }

        if (
            ($onlyTreeId !== null)
            && ($job->treeId !== $onlyTreeId)
        ) {
            // A tree-scoped drain leaves a foreign job for another run: release it back to the pool.
            $this->transport->release($job->jobId);

            return DrainOutcome::skipped();
        }

        // The store is built PER JOB so a multi-tree drain persists each job to its own tree-scoped
        // store; the ingest stays store-agnostic.
        $store          = $this->storeForTree($tree);
        $candidatesById = $this->repository->findByXrefs($tree, $job->requestedPersonIds);

        // The ingest AND the markIngested finalisation share ONE try so a deterministically-throwing job
        // is parked rather than released back to done — otherwise it would be re-processed every drain
        // (head-of-line starvation). markIngested() itself throws on a failed ingesting -> ingested
        // rename, so it must sit inside the guard too rather than crash the whole drain. The park goes
        // through parkFailed(), which likewise tolerates a park failure for the same reason.
        try {
            $result = $this->ingest->ingest($job->notices, $candidatesById, $store);
            $this->transport->markIngested(
                $job->jobId,
                [
                    'noticesRead'     => $result->noticesRead,
                    'candidatesFound' => $result->candidatesFound,
                    'matchesStored'   => $result->matchesStored,
                ],
                $result->warnings,
            );

            return DrainOutcome::ingested($result->matchesStored);
        } catch (Throwable) {
            $this->parkFailed($job->jobId, 'ingest_failed');

            return DrainOutcome::failed();
        }
    }

    /**
     * Parks a job under its failure category through the transport, tolerating a failure of the park
     * itself. A park is a finalisation step that can throw: the file transport renames `ingesting/` ->
     * `failed-ingest/` (a {@see \RuntimeException} when the rename fails), and any transport's park
     * touches the queue filesystem. Swallowing a park failure here keeps one un-parkable job from
     * aborting the whole drain — head-of-line starvation of the still-healthy jobs queued behind it.
     * The un-parked job simply stays claimed for the next run or manual recovery (file: left in
     * `ingesting/`, reported by staleCount; REST: the ledger entry survives and re-polls), a bounded,
     * non-corrupting, self-healing degradation tracked in issue #71. This mirrors why the markIngested
     * finalisation sits inside the ingest guard rather than crashing the drain.
     *
     * @param string $jobId          The job to park.
     * @param string $reasonCategory The snake_case category classifying the failure.
     *
     * @return void
     */
    private function parkFailed(string $jobId, string $reasonCategory): void
    {
        try {
            $this->transport->markFailed($jobId, $reasonCategory);
        } catch (Throwable) {
            // A park-rename/unlink fault must not abort the drain; the job stays claimed for the next
            // run or manual recovery (file: counted by staleCount; REST: re-polls). Bounded — see #71.
        }
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
}
