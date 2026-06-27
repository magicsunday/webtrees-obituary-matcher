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
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

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
#[UsesClass(QueuePaths::class)]
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
        return [
            'treeId not an int'                       => ['{"jobId":"x","treeId":"7","requestedPersonIds":[],"submittedAt":"t"}'],
            'jobId not a string'                      => ['{"jobId":7,"treeId":1,"requestedPersonIds":[],"submittedAt":"t"}'],
            'submittedAt not a string'                => ['{"jobId":"x","treeId":1,"requestedPersonIds":[],"submittedAt":5}'],
            'requestedPersonIds not an array'         => ['{"jobId":"x","treeId":1,"requestedPersonIds":"nope","submittedAt":"t"}'],
            'a non-string requestedPersonIds element' => ['{"jobId":"x","treeId":1,"requestedPersonIds":[1],"submittedAt":"t"}'],
        ];
    }
}
