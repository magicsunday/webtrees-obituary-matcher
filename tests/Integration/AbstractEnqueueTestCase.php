<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use DateTimeImmutable;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\FileJobTransport;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;

use function hash;
use function mkdir;
use function scandir;
use function str_starts_with;

use const SCANDIR_SORT_NONE;

/**
 * Shared harness for the enqueue integration tests: a throwaway file-drop queue + isolated per-tree
 * store (the plumbing lives in {@see AbstractQueueStoreTestCase}), the {@see EnqueueService} wired
 * through the SAME graph the `tools/enqueue.php` CLI assembles (the only seams redirected are
 * {@see EnqueueService::storeForTree()} → the isolated store and {@see EnqueueService::now()} → a
 * fixed instant), plus seeders for in-flight jobs and store rows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractEnqueueTestCase extends AbstractQueueStoreTestCase
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    protected function tempDirPrefix(): string
    {
        return 'obituary-enqueue-';
    }

    /**
     * Build the {@see EnqueueService} through the real CLI wiring, redirecting only the store seam
     * (to the isolated root) and the clock seam (to a fixed instant for a stable jobId).
     *
     * @return EnqueueService
     */
    protected function enqueueService(): EnqueueService
    {
        $paths    = $this->paths();
        $storeDir = $this->storeRoot;

        // Build the file transport over this test's throwaway queue exactly as EnqueueServiceFactory
        // does, so the test drives the real composition root; only the store + clock seams are pinned.
        $transport = new FileJobTransport(
            new QueueClient($paths),
            new ResponseReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            $paths,
        );

        return new class(new CandidateRepository(), new FeederRequestFactory(new QueryGenerator()), new UrlHostNormalizer(), new TreeService(new GedcomImportService()), $transport, $storeDir) extends EnqueueService {
            /**
             * @param CandidateRepository  $repository     The candidate repository.
             * @param FeederRequestFactory $requestFactory The request assembler.
             * @param UrlHostNormalizer    $hostNormalizer The canonical-host helper.
             * @param TreeService          $treeService    The tree lookup.
             * @param JobTransport         $transport      The file-drop job transport.
             * @param string               $storeRoot      The isolated per-tree store base directory.
             */
            public function __construct(
                CandidateRepository $repository,
                FeederRequestFactory $requestFactory,
                UrlHostNormalizer $hostNormalizer,
                TreeService $treeService,
                JobTransport $transport,
                private readonly string $storeRoot,
            ) {
                parent::__construct($repository, $requestFactory, $hostNormalizer, $treeService, $transport);
            }

            /**
             * Redirect the per-tree store to an isolated directory under the test root.
             *
             * @param Tree $tree The tree whose store is requested.
             *
             * @return MatchStore The isolated, tree-scoped store.
             */
            protected function storeForTree(Tree $tree): MatchStore
            {
                return new FileMatchStore(MatchStoreFactory::pathForTree($this->storeRoot, $tree));
            }

            /**
             * Pin the clock so the minted jobId and the createdAt stamp are deterministic.
             *
             * @return DateTimeImmutable The fixed instant.
             */
            protected function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-06-23T10:15:30+00:00');
            }
        };
    }

    /**
     * List the real job directories currently in the queued state (excluding dot + temp entries), in
     * filesystem order.
     *
     * @return list<string> The queued job ids.
     */
    protected function queuedJobIds(): array
    {
        $root    = $this->paths()->stateRoot(JobState::Queued->value);
        $entries = scandir($root, SCANDIR_SORT_NONE);

        if ($entries === false) {
            return [];
        }

        $jobs = [];

        foreach ($entries as $entry) {
            if (
                ($entry === '.')
                || ($entry === '..')
                || str_starts_with($entry, '.tmp-')
            ) {
                continue;
            }

            $jobs[] = $entry;
        }

        return $jobs;
    }

    /**
     * Seed an in-flight job in the given state carrying a schema-3 request for the given persons, so
     * the in-flight dedup scan sees those personIds as already queued.
     *
     * @param string       $jobId     The job id (directory name).
     * @param JobState     $state     The in-flight state to seed into.
     * @param int          $treeId    The tree the request belongs to.
     * @param list<string> $personIds The requested person ids.
     *
     * @return void
     */
    protected function seedInflightJob(string $jobId, JobState $state, int $treeId, array $personIds): void
    {
        $dir = $this->paths()->stateRoot($state->value) . '/' . $jobId;
        mkdir($dir, 0o700, true);

        $candidates = [];

        foreach ($personIds as $personId) {
            $candidates[] = ['personId' => $personId, 'queries' => [], 'excludedHosts' => []];
        }

        AtomicFile::writeJson(
            $dir . '/request.json',
            [
                'schemaVersion' => 3,
                'jobId'         => $jobId,
                'createdAt'     => '2026-06-20T09:00:00+00:00',
                'locale'        => 'de-DE',
                'candidates'    => $candidates,
                'treeId'        => $treeId,
            ],
        );
    }

    /**
     * Seed a stored match row into the isolated per-tree store in the requested status. The row is
     * always written as Pending first (the only status {@see FileMatchStore::upsertPending()} writes),
     * then transitioned to the target status via the matching store transition.
     *
     * @param Tree        $tree     The tree whose store is seeded.
     * @param string      $personId The candidate id.
     * @param string      $url      The source notice URL (its host becomes a candidate excluded host).
     * @param MatchStatus $status   The target row status.
     *
     * @return void
     */
    protected function seedStoreRow(Tree $tree, string $personId, string $url, MatchStatus $status): void
    {
        $store = new FileMatchStore(MatchStoreFactory::pathForTree($this->storeRoot, $tree));

        // upsertPending only ever writes a Pending row, so every other status is reached by following
        // it with the matching transition keyed on (personId, url).
        $store->upsertPending(
            new StoredMatch($personId, $url, MatchStatus::Pending, ClassifiedMatch::emptyArray($personId, $url)),
        );

        switch ($status) {
            case MatchStatus::Pending:
                break;

            case MatchStatus::Uncertain:
                $store->markUncertain($personId, $url, null);

                break;

            case MatchStatus::Rejected:
                $store->markRejected($personId, $url, null);

                break;

            case MatchStatus::Confirmed:
                // A valid WriteBack is awkward to assemble in a test, so the Confirmed row is written
                // directly: read the just-written Pending row back, flip its status, and re-persist it
                // through the same FileMatchStore (which validates the row key + per-person sub-dir).
                $this->writeConfirmedRow($tree, $personId, $url);

                break;
        }
    }

    /**
     * Hand-write a Confirmed row by re-persisting the existing Pending row with `status => confirmed`,
     * so the excludedHosts scan sees a terminal row on that host without building a real WriteBack.
     *
     * @param Tree   $tree     The tree whose store holds the row.
     * @param string $personId The candidate id.
     * @param string $url      The source notice URL.
     *
     * @return void
     */
    private function writeConfirmedRow(Tree $tree, string $personId, string $url): void
    {
        $row = (new StoredMatch($personId, $url, MatchStatus::Confirmed, ClassifiedMatch::emptyArray($personId, $url)))->toArray();

        $storeDir  = MatchStoreFactory::pathForTree($this->storeRoot, $tree);
        $personDir = $storeDir . '/' . hash('sha256', $personId);

        // The file name is the SHA-256 of the identity-normalised URL, the same row key the store
        // derives from the raw URL; reuse the store's own helper so the layout cannot drift.
        $rowKey = StoredMatchKey::fromUrl($url);

        AtomicFile::ensureDirectory($personDir);
        AtomicFile::writeJson($personDir . '/' . $rowKey . '.json', $row);
    }

    /**
     * Import a multi-person tree of old "Searchable" individuals (I1..I3) with no death date — so
     * every one is a rebuildable candidate — born old enough to clear the age bound the test pins.
     *
     * @param string $name The unique tree name (each scenario needs a distinct tree).
     *
     * @return Tree The imported tree.
     */
    protected function ottoTree(string $name): Tree
    {
        $body = $this->indi('I1', 'Otto', '17 MAR 1930')
            . $this->indi('I2', 'Berta', '03 JUN 1928')
            . $this->indi('I3', 'Cesar', '21 NOV 1925');

        return $this->importFixtureTree($this->gedcom($body), $name);
    }

    /**
     * Import a tree of {@see $count} old "Searchable" individuals (I1..I{count}), every one an
     * eligible candidate with no death date — so a `--limit` below {@see $count} forces the producer
     * to bound the set. Restricted to a single-digit count so the I1..I9 xrefs sort identically under
     * lexicographic and numeric ordering.
     *
     * @param string $name  The unique tree name.
     * @param int    $count The number of eligible individuals (1..9).
     *
     * @return Tree The imported tree.
     */
    protected function searchableTree(string $name, int $count): Tree
    {
        $body = '';

        for ($n = 1; $n <= $count; ++$n) {
            $body .= $this->indi('I' . $n, 'Person' . $n, '17 MAR 1930');
        }

        return $this->importFixtureTree($this->gedcom($body), $name);
    }

    /**
     * Wrap a sequence of INDI records in the shared GEDCOM header + trailer, so the fixture-tree
     * builders ({@see ottoTree()}, {@see searchableTree()}) do not each repeat the boilerplate.
     *
     * @param string $body The concatenated INDI records.
     *
     * @return string The full GEDCOM document.
     */
    private function gedcom(string $body): string
    {
        return "0 HEAD\n"
            . "1 SOUR obituary-matcher-tests\n"
            . "1 GEDC\n"
            . "2 VERS 5.5.1\n"
            . "1 CHAR UTF-8\n"
            . $body
            . "0 TRLR\n";
    }

    /**
     * Build one INDI record for a "Searchable" individual with a birth date and no death date.
     *
     * @param string $xref  The xref (e.g. "I1").
     * @param string $given The given name.
     * @param string $birth The GEDCOM birth date (e.g. "17 MAR 1930").
     *
     * @return string The GEDCOM INDI record.
     */
    private function indi(string $xref, string $given, string $birth): string
    {
        return '0 @' . $xref . "@ INDI\n"
            . '1 NAME ' . $given . " /Searchable/\n"
            . '2 GIVN ' . $given . "\n"
            . "2 SURN Searchable\n"
            . "1 SEX M\n"
            . "1 BIRT\n"
            . '2 DATE ' . $birth . "\n";
    }
}
