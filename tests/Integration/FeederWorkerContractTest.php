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
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function fclose;
use function feof;
use function fread;
use function getenv;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_select;
use function stream_set_blocking;
use function trim;
use function usleep;

use const PHP_OS_FAMILY;

/**
 * The cross-language contract harness: it proves the first real vertical slice of the
 * module↔feeder chain end to end — a PHP {@see QueueClient} enqueues a request, the REAL Python
 * feeder worker drains it against recorded portal HTML (no network), and the PHP
 * {@see ResponseReader}/{@see IngestService} pipeline ingests the resulting response.json into a
 * pending suggestion.
 *
 * The test is availability-gated: it runs only when the environment exposes the feeder worker via
 * the OBITUARY_FINDER_WORKER variable (the command that invokes
 * `obituary-finder/scripts/worker_fixture_run.py`), and is skipped otherwise — so the normal PHP
 * test run, which has no Python runtime, stays green while the contract is still pinned wherever
 * both halves are available (a combined CI stage or a developer running both repos).
 *
 * The worker is handed this test's own recorded HTML fixture (FIXTURE_HTML_DIR) and the queue this
 * test enqueued into (QUEUE_DIR), so the matcher fully controls the input and the assertion is
 * deterministic. The recorded fixture carries a "Erika Mustermann geb. Beispiel" notice
 * (* 22.06.1951, † 07.08.2025) the candidate below is built to match.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class FeederWorkerContractTest extends TempDirTestCase
{
    /**
     * @var int The deadline, in seconds, the worker subprocess must finish within. A deterministic
     *          fixture-drain worker finishes in well under a second; the bound only stops a wedged
     *          worker from hanging the whole suite — it fails the test with the captured output.
     */
    private const int WORKER_TIMEOUT_SECONDS = 60;

    /**
     * @var int The grace, in seconds, a timed-out worker is given to exit on SIGTERM before it is
     *          escalated to an uncatchable SIGKILL, so reaping a wedged worker can never block.
     */
    private const int TERMINATE_GRACE_SECONDS = 5;

    /**
     * @var string The neutral filename of this test's recorded HTML fixture, passed to the worker as
     *             FIXTURE_FILE so the public repo carries no portal-named fixture.
     */
    private const string FIXTURE_FILE = 'obituary_portal_result.html';

    /**
     * @var int The numeric tree id stamped onto the enqueued request. It is asserted to round-trip
     *          unchanged into the on-disk request.json, so the build-site and the contract assertion
     *          share one source and cannot silently drift.
     */
    private const int REQUEST_TREE_ID = 11;

    /**
     * Drives the full request → real worker → response → ingest chain and asserts a pending
     * suggestion for the recorded obituary is stored.
     *
     * @return void
     */
    #[Test]
    public function thePhpQueueAndRealWorkerProduceAPendingSuggestionEndToEnd(): void
    {
        $worker = getenv('OBITUARY_FINDER_WORKER');

        if (
            ($worker === false)
            || (trim($worker) === '')
        ) {
            self::markTestSkipped(
                'Set OBITUARY_FINDER_WORKER to the feeder worker command (for example '
                . '"python3 /path/to/obituary-finder/scripts/worker_fixture_run.py") to run the '
                . 'cross-language contract harness; the PHP-only test run has no Python runtime.'
            );
        }

        $candidate = $this->matchingCandidate();

        // 1. The PHP client enqueues a request for the candidate the recorded notice matches.
        $paths   = new QueuePaths($this->tmp);
        $client  = new QueueClient($paths);
        $request = (new FeederRequestFactory(new QueryGenerator()))->build(
            'm2-contract-1',
            new DateTimeImmutable('2026-06-21T00:00:00+00:00'),
            'de-DE',
            [$candidate],
            self::REQUEST_TREE_ID,
        );
        $jobId = $client->enqueue($request);

        // 1a. Pin the REQUEST contract the Python feeder consumes. The request payload is at
        //     schemaVersion 3 and carries the numeric `treeId` field (so the drain can resolve the target
        //     tree without trusting the worker). Assert both against the request as ENQUEUED on disk —
        //     the exact bytes the worker reads — not just the in-memory object.
        //
        //     CONTRACT: the private Python feeder's request parser MUST tolerate `schemaVersion` 3 and the
        //     new per-candidate `excludedHosts` field (a feeder hint that is ALWAYS present on every
        //     candidate and is a list — possibly empty when the producer seeded no open match) — and
        //     carry the `treeId` field through opaquely into the response flow — before this schema bump
        //     ships, or the worker will reject every request this module now enqueues.
        $enqueued = AtomicFile::readJsonCapped($paths->queuedDir($jobId) . '/request.json', 1_048_576);

        self::assertSame(3, $enqueued['schemaVersion']);
        self::assertSame(self::REQUEST_TREE_ID, $enqueued['treeId']);

        self::assertArrayHasKey('candidates', $enqueued);
        self::assertIsArray($enqueued['candidates']);
        self::assertArrayHasKey(0, $enqueued['candidates']);

        $firstCandidate = $enqueued['candidates'][0];
        self::assertIsArray($firstCandidate);

        self::assertArrayHasKey('excludedHosts', $firstCandidate);
        self::assertIsList($firstCandidate['excludedHosts']);
        // The empty list is the most important default semantic: when the producer seeds no open
        // match, excludedHosts is present-and-[] (never absent), so the feeder never distinguishes
        // "absent" from "empty". Pin it explicitly (the contract test enqueues with no store rows).
        self::assertSame([], $firstCandidate['excludedHosts']);

        // 2. The REAL Python worker drains the queue against this test's recorded HTML fixture.
        $this->runWorker($worker);

        // 3. The worker published a terminal done job carrying its response.json.
        self::assertFileExists($paths->doneDir($jobId) . '/response.json');

        // 4. The module claims the done job into the ingesting state — the drain reads the CLAIMED
        //    response, so the claim must win before the ingest can find it.
        self::assertTrue($client->claimForIngest($jobId));

        // 5. The production read + ingest pipeline consumes the untrusted response.json.
        $store   = new FileMatchStore($this->tmp . '/store');
        $service = new IngestService(
            new ResponseReader($paths),
            new EnrichedMatchEngine(),
            new Classifier(),
        );
        $result = $service->ingest($jobId, [$candidate->id], [$candidate->id => $candidate], $store);

        // 6. The fixture pins exactly two notices (Erika with dates, Max without), so the chain
        //    persists exactly two pending suggestions for the requested person — a dropped or
        //    collapsed notice fails this, which a loose ">= 1" lower bound would not catch.
        self::assertSame(2, $result->matchesStored);

        $pending = $store->allPending();
        self::assertCount(2, $pending);

        $byUrl = [];

        foreach ($pending as $match) {
            // Every stored suggestion belongs to the requested person (the ownership boundary held).
            self::assertSame($candidate->id, $match->personId);
            $byUrl[$match->obituaryUrl] = $match;
        }

        $erikaUrl = 'https://obituary.example/notice/erika-mustermann';
        $maxUrl   = 'https://obituary.example/notice/max-mustermann';

        // Both recorded notices ingested for the person (order-independent: nothing pins the worker's
        // iteration order, only the resulting set of obituary URLs).
        self::assertEqualsCanonicalizing([$erikaUrl, $maxUrl], array_keys($byUrl));

        // The deliberately strong Erika candidate (exact name + exact birth date 22.06.1951) must
        // round-trip as a real positive classification, proving the SCORE survived the cross-process
        // boundary intact — not merely that the plumbing carried a URL.
        self::assertContains($byUrl[$erikaUrl]->match['classification'], ['strong', 'probable', 'possible']);

        // The enriched engine harvests the notice's exact death date directly off the cross-process
        // response (the recorded Erika notice died 07.08.2025), proving the harvested fact survived
        // the whole chain — a Phase-1 obituary down-map or a dropped harvest would fail this.
        self::assertSame('2025-08-07', $byUrl[$erikaUrl]->match['extractedFacts']['deathDate']);
    }

    /**
     * Runs the feeder worker command against this test's queue and recorded HTML fixture, asserting
     * it drains cleanly. QUEUE_DIR / FIXTURE_HTML_DIR are the worker's documented inputs.
     *
     * @param string $worker The worker command from OBITUARY_FINDER_WORKER.
     *
     * @return void
     */
    private function runWorker(string $worker): void
    {
        $environment                     = getenv();
        $environment['QUEUE_DIR']        = $this->tmp;
        $environment['FIXTURE_HTML_DIR'] = __DIR__ . '/fixtures';
        // Name the recorded HTML file explicitly so this repo's fixture carries a neutral filename
        // rather than depending on the worker's portal-named default.
        $environment['FIXTURE_FILE'] = self::FIXTURE_FILE;

        // Read stdin from the platform null device so the worker can never block waiting on an
        // inherited stdin (a CI runner's stdin may be a pipe that never closes).
        $nullDevice = (PHP_OS_FAMILY === 'Windows') ? 'NUL' : '/dev/null';

        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($worker, $descriptors, $pipes, null, $environment);

        if (!is_resource($process)) {
            self::fail(sprintf('Failed to start the feeder worker: %s', $worker));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;

        // Drain both pipes until the worker closes them (EOF), bounding the wait: a wedged worker
        // must fail the test with its captured output, never hang the whole suite indefinitely.
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if (microtime(true) >= $deadline) {
                $this->terminate($process, $pipes);

                self::fail(sprintf(
                    "The feeder worker did not finish within %d s.\nstdout: %s\nstderr: %s",
                    self::WORKER_TIMEOUT_SECONDS,
                    $stdout,
                    $stderr
                ));
            }

            // Only wait on pipes that have NOT yet hit EOF. stream_select reports a closed pipe as
            // perpetually readable, so leaving an already-EOF pipe in the set would return
            // immediately every iteration and spin the loop at 100% CPU until the other pipe also
            // closes; filtering keeps the 1s poll as the real throttle. The while condition
            // guarantees at least one pipe is still open, so $read is never empty here.
            $read = [];

            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            $write  = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    continue;
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            sprintf("The feeder worker exited with %d.\nstdout: %s\nstderr: %s", $exitCode, $stdout, $stderr)
        );
    }

    /**
     * Forcibly terminates a timed-out worker so reaping it can never hang. Closes our pipe ends
     * first (a child blocked writing into a full, no-longer-drained pipe would deadlock proc_close),
     * sends SIGTERM, and escalates to an uncatchable SIGKILL when the child has not exited within the
     * grace window — only then reaps with proc_close, which therefore cannot block indefinitely.
     *
     * @param resource             $process The worker process handle.
     * @param array<int, resource> $pipes   The worker's open stdout/stderr pipes.
     *
     * @return void
     */
    private function terminate($process, array $pipes): void
    {
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_terminate($process);

        $killDeadline = microtime(true) + self::TERMINATE_GRACE_SECONDS;

        while (proc_get_status($process)['running'] === true) {
            if (microtime(true) >= $killDeadline) {
                proc_terminate($process, 9);

                break;
            }

            usleep(50000);
        }

        proc_close($process);
    }

    /**
     * Builds the candidate the recorded "Erika Mustermann geb. Beispiel" notice matches: given name
     * Erika, married surname Mustermann, birth surname Beispiel, born exactly on 22.06.1951 (the
     * notice's birth date) so name and birth both score a strong positive signal.
     *
     * @return PersonCandidate
     */
    private function matchingCandidate(): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::exact(new DateValue(1951, 6, 22)),
            null,
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );
    }
}
