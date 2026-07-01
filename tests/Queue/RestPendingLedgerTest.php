<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\JobId;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function array_fill;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;

/**
 * Tests the slim local ledger of in-flight REST jobs: record/list/remove round-trips, idempotent
 * removal of unknown jobs, poison-tolerant scanning over malformed or foreign files, and rejection of
 * a path-traversal-unsafe jobId on the write path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RestPendingLedger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(JobId::class)]
final class RestPendingLedgerTest extends TempDirTestCase
{
    /**
     * A recorded entry is listed by entries()/jobIds() with its narrowed fields and is removed again.
     *
     * @return void
     */
    #[Test]
    public function aRecordedEntryIsListedAndRemovable(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');
        $ledger->record('job-1', 7, ['I1', 'I2'], '2024-05-21T08:29:55Z');

        $entries = iterator_to_array($ledger->entries());

        self::assertCount(1, $entries);
        self::assertSame('job-1', $entries[0]['jobId']);
        self::assertSame(7, $entries[0]['treeId']);
        self::assertSame(['I1', 'I2'], $entries[0]['requestedPersonIds']);
        self::assertSame('2024-05-21T08:29:55Z', $entries[0]['submittedAt']);
        self::assertSame(['job-1'], $ledger->jobIds());

        $ledger->remove('job-1');

        self::assertSame([], iterator_to_array($ledger->entries()));
        self::assertSame([], $ledger->jobIds());
    }

    /**
     * Every path-safe pending ledger file is counted as an open job by openJobCount().
     *
     * @return void
     */
    #[Test]
    public function openJobCountCountsEveryPathSafeLedgerFile(): void
    {
        $root   = $this->tmp . '/rest-pending';
        $ledger = new RestPendingLedger($root);
        $ledger->record('job-a', 1, ['X1'], '2026-06-30T10:00:00Z');
        $ledger->record('job-b', 1, ['X2'], '2026-06-30T10:01:00Z');

        self::assertSame(2, $ledger->openJobCount());
    }

    /**
     * A malformed entry still strands a remote job, so it MUST count as open (unlike entries()/jobIds()).
     *
     * @return void
     */
    #[Test]
    public function openJobCountIncludesAMalformedButPathSafeEntry(): void
    {
        $root   = $this->tmp . '/rest-pending';
        $ledger = new RestPendingLedger($root);
        $ledger->record('job-a', 1, ['X1'], '2026-06-30T10:00:00Z');
        file_put_contents($root . '/job-b.json', '{ not valid json');

        self::assertSame(2, $ledger->openJobCount());   // entries() would drop job-b; openJobCount keeps it
    }

    /**
     * openJobCount() counts by FILENAME safety, not content: a `.json` file whose basename is not a
     * path-safe jobId (here an embedded dot) is skipped by the isSafeForStorage guard, and a `.json`
     * DIRECTORY is skipped by the isFile guard. Neither strands a remote job, so neither is counted —
     * pinning the two exclusion branches a content-based counter would miss.
     *
     * @return void
     */
    #[Test]
    public function openJobCountExcludesUnsafeNamesAndDirectories(): void
    {
        $root   = $this->tmp . '/rest-pending';
        $ledger = new RestPendingLedger($root);
        $ledger->record('job-a', 1, ['X1'], '2026-06-30T10:00:00Z');

        // A path-UNSAFE basename (an embedded dot fails ^[A-Za-z0-9_-]{1,64}$) and a path-safe *.json
        // DIRECTORY are both present beside the one real job.
        file_put_contents($root . '/foo.bar.json', '{}');
        mkdir($root . '/adir.json');

        self::assertSame(1, $ledger->openJobCount());
    }

    /**
     * Removing a job that was never recorded is a no-op rather than a fatal, even before the root
     * directory exists.
     *
     * @return void
     */
    #[Test]
    public function removeIsIdempotentForAnUnknownJob(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        $ledger->remove('nope');

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * Removing an invalid (path-traversal-unsafe) jobId is a silent no-op: no path is ever built from
     * the unvalidated id, so removal cannot escape the ledger root.
     *
     * @return void
     */
    #[Test]
    public function removeIgnoresAnInvalidJobId(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        $ledger->remove('../evil');

        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A malformed JSON file in the root is skipped, not fatal: the scan stays poison-tolerant.
     *
     * @return void
     */
    #[Test]
    public function aMalformedFileIsSkippedNotFatal(): void
    {
        mkdir($this->tmp . '/rest-pending', 0o700, true);
        file_put_contents($this->tmp . '/rest-pending/garbage.json', '{not json');

        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        self::assertSame([], iterator_to_array($ledger->entries()));
        self::assertSame([], $ledger->jobIds());
    }

    /**
     * A foreign file whose basename fails the jobId guard, and a structurally invalid entry (wrong field
     * types), are both skipped while a valid sibling entry still surfaces.
     *
     * @return void
     */
    #[Test]
    public function foreignAndStructurallyInvalidFilesAreSkipped(): void
    {
        $root = $this->tmp . '/rest-pending';
        mkdir($root, 0o700, true);

        // Foreign basename (contains a '!', fails the ^[A-Za-z0-9_-]{1,64}$ guard).
        file_put_contents($root . '/not!a!job.json', '{"jobId":"x","treeId":1,"requestedPersonIds":[],"submittedAt":"t"}');

        // Valid filename but the wrong type for treeId (string, not int) → narrowing fails.
        file_put_contents($root . '/badshape.json', '{"jobId":"badshape","treeId":"7","requestedPersonIds":[],"submittedAt":"t"}');

        $ledger = new RestPendingLedger($root);
        $ledger->record('good-job', 3, ['I9'], '2024-01-01T00:00:00Z');

        $entries = iterator_to_array($ledger->entries());

        self::assertCount(1, $entries);
        self::assertSame('good-job', $entries[0]['jobId']);
        self::assertSame(['good-job'], $ledger->jobIds());
    }

    /**
     * A file whose basename passes the guard but whose payload jobId disagrees with that basename — a
     * corrupt or planted file — is skipped, so the yielded jobId is always the authoritative path-safe
     * basename and never an unvalidated content string (which could be a traversal sink). A valid sibling
     * still surfaces.
     *
     * @return void
     */
    #[Test]
    public function aPayloadJobIdDisagreeingWithItsFilenameIsSkipped(): void
    {
        $root = $this->tmp . '/rest-pending';
        mkdir($root, 0o700, true);

        // Basename "aaa" passes the guard, but the payload claims to be a different (valid) id.
        file_put_contents($root . '/aaa.json', '{"jobId":"bbb","treeId":1,"requestedPersonIds":[],"submittedAt":"t"}');

        // Basename "ccc" passes the guard, but the payload jobId is a path-traversal string that the
        // basename guard would never have admitted — it must never be yielded as an entry's identity.
        file_put_contents($root . '/ccc.json', '{"jobId":"../../evil","treeId":1,"requestedPersonIds":[],"submittedAt":"t"}');

        $ledger = new RestPendingLedger($root);
        $ledger->record('good-job', 3, ['I9'], '2024-01-01T00:00:00Z');

        $entries = iterator_to_array($ledger->entries());

        self::assertCount(1, $entries);
        self::assertSame('good-job', $entries[0]['jobId']);
        self::assertSame(['good-job'], $ledger->jobIds());
    }

    /**
     * Recording an entry whose encoded payload would exceed the read-back byte cap is rejected loudly
     * BEFORE any file is written, so a job that the capped scan could never read back can never be
     * silently orphaned on disk. No entry is created under the root afterwards.
     *
     * @return void
     */
    #[Test]
    public function recordRejectsAnOversizedEntry(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        // ~132 KiB of person ids, well past the 64 KiB ENTRY_MAX_BYTES read-back cap.
        $requestedPersonIds = array_fill(0, 12_000, 'I1234567');

        try {
            $ledger->record('huge', 7, $requestedPersonIds, '2024-01-01T00:00:00Z');
            self::fail('Expected a RuntimeException for an oversized ledger entry.');
        } catch (RuntimeException) {
            // Expected: the oversized entry is rejected before the write.
        }

        self::assertSame([], iterator_to_array($ledger->entries()));
        self::assertSame([], $ledger->jobIds());
    }

    /**
     * Recording a job under a path-traversal-unsafe jobId is rejected before any file is written.
     *
     * @return void
     */
    #[Test]
    public function recordRejectsAnInvalidJobId(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        $this->expectException(InvalidArgumentException::class);

        $ledger->record('../escape', 1, [], '2024-01-01T00:00:00Z');
    }

    /**
     * A jobId carrying a trailing newline is rejected before any file is written: the `$` end-anchor of
     * the shared path-traversal guard is `D`-modified, so "job-1\n" can never become a "job-1\n.json"
     * sink. No entry is created under the root afterwards.
     *
     * @return void
     */
    #[Test]
    public function recordRejectsAJobIdWithATrailingNewline(): void
    {
        $ledger = new RestPendingLedger($this->tmp . '/rest-pending');

        try {
            $ledger->record("job-1\n", 7, [], '2024-01-01T00:00:00Z');
            self::fail('Expected an InvalidArgumentException for a trailing-newline jobId.');
        } catch (InvalidArgumentException) {
            // Expected: the trailing-newline jobId is rejected.
        }

        self::assertSame([], iterator_to_array($ledger->entries()));
        self::assertSame([], $ledger->jobIds());
    }

    /**
     * Every distinct field-shape failure in narrow() rejects the malformed entry (it is absent from
     * entries()/jobIds()) while a valid sibling entry still surfaces, so one structurally invalid file
     * can never poison the scan.
     *
     * @param string $malformedJson The raw JSON bytes of the structurally invalid entry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('structurallyInvalidEntries')]
    public function aStructurallyInvalidEntryIsSkippedWhileAValidSiblingSurfaces(string $malformedJson): void
    {
        $root = $this->tmp . '/rest-pending';
        mkdir($root, 0o700, true);

        // Valid filename so the basename guard passes; only narrow() may reject it.
        file_put_contents($root . '/bad.json', $malformedJson);

        $ledger = new RestPendingLedger($root);
        $ledger->record('good-job', 3, ['I9'], '2024-01-01T00:00:00Z');

        $entries = iterator_to_array($ledger->entries());

        self::assertCount(1, $entries);
        self::assertSame('good-job', $entries[0]['jobId']);
        self::assertSame(['good-job'], $ledger->jobIds());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function structurallyInvalidEntries(): array
    {
        // The payload jobId matches the "bad" basename in every row except the "jobId not a string"
        // row (where the jobId field itself is the malformed one), so each row isolates the DISTINCT
        // narrow() rejection branch it names rather than all collapsing onto the basename-mismatch check.
        return [
            'treeId not an int'                       => ['{"jobId":"bad","treeId":"7","requestedPersonIds":[],"submittedAt":"t"}'],
            'jobId not a string'                      => ['{"jobId":7,"treeId":1,"requestedPersonIds":[],"submittedAt":"t"}'],
            'submittedAt not a string'                => ['{"jobId":"bad","treeId":1,"requestedPersonIds":[],"submittedAt":5}'],
            'requestedPersonIds not an array'         => ['{"jobId":"bad","treeId":1,"requestedPersonIds":"nope","submittedAt":"t"}'],
            'a non-string requestedPersonIds element' => ['{"jobId":"bad","treeId":1,"requestedPersonIds":[1],"submittedAt":"t"}'],
        ];
    }
}
