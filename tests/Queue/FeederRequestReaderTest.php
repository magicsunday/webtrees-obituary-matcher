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
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function preg_quote;

/**
 * Tests the validating reader of the claimed job's request.json: it narrows the trusted-directory
 * payload into the numeric tree id and the requested person ids, and rejects every malformed
 * request (wrong schema version, mismatched job id, non-int tree id, missing/scalar candidates, a
 * scalar candidate and a non-string/empty candidate person id) with a dedicated validation
 * exception, and rejects a traversal job id with the path-traversal guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FeederRequestReader::class)]
#[CoversClass(ResponseValidationException::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(AtomicFile::class)]
final class FeederRequestReaderTest extends QueueTempDirTestCase
{
    /**
     * A valid v2 request is narrowed into the numeric tree id and the ordered list of requested
     * person ids.
     */
    #[Test]
    public function readsTreeIdAndRequestedPersonIds(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [
                ['personId' => 'X1', 'queries' => []],
                ['personId' => 'X2', 'queries' => []],
            ],
            'treeId' => 11,
        ]);

        $result = (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');

        self::assertSame(11, $result['treeId']);
        self::assertSame(['X1', 'X2'], $result['requestedPersonIds']);
    }

    /**
     * A request whose schema version is not the accepted one is rejected.
     */
    #[Test]
    public function rejectsAWrongSchemaVersion(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 1,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => 'X1', 'queries' => []]],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Unknown or missing request schema version.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * A request whose embedded job id does not match the directory it was read from is rejected.
     */
    #[Test]
    public function rejectsAJobIdThatDoesNotMatchTheDirectory(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-2',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => 'X1', 'queries' => []]],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Request jobId does not match the claimed job.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * A request whose tree id is not an integer is rejected.
     */
    #[Test]
    public function rejectsANonIntegerTreeId(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => 'X1', 'queries' => []]],
            'treeId'        => '11',
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Request treeId is missing or not an integer.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * A request whose candidates field is missing or not a list is rejected.
     */
    #[Test]
    public function rejectsMissingCandidates(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => 'X1',
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Request candidates is missing or not a list.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * A request carrying a scalar candidate (not an object) is rejected.
     */
    #[Test]
    public function rejectsAScalarCandidate(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => ['X1'],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Request candidate is not an object.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * A request carrying a candidate whose person id is empty or not a string is rejected; both
     * clauses of the guard are pinned.
     *
     * @param string|int $personId The malformed candidate person id under test.
     */
    #[Test]
    #[DataProvider('malformedPersonIdProvider')]
    public function rejectsAMalformedCandidatePersonId(string|int $personId): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 3,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => $personId, 'queries' => []]],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Request candidate personId is missing, not a string or empty.', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('job-1');
    }

    /**
     * Provides the malformed candidate person ids that must be rejected: the empty-string clause and
     * the non-string clause of the guard.
     *
     * @return array<string, array{0: string|int}>
     */
    public static function malformedPersonIdProvider(): array
    {
        return [
            'empty string' => [''],
            'non-string'   => [123],
        ];
    }

    /**
     * A traversal job identifier is rejected by the path-traversal guard before any file is read,
     * keeping the docblock's "validated" claim true.
     */
    #[Test]
    public function rejectsATraversalJobId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote('Invalid job identifier:', '/') . '/'
        );
        (new FeederRequestReader(new QueuePaths($this->tmp), QueueLimits::FEEDER_FILE_MAX_BYTES))->read('../../etc');
    }

    /**
     * Writes the given decoded request payload into done/<jobId>/request.json, simulating a claimed
     * job awaiting the drain.
     *
     * @param string                                                                $jobId   The job identifier whose state directory receives the request.
     * @param array<string, scalar|list<scalar|array<string, scalar|list<scalar>>>> $payload The decoded request payload to write.
     *
     * @return void
     */
    private function seedRequest(string $jobId, array $payload): void
    {
        $dir = (new QueuePaths($this->tmp))->stateRoot(JobState::Done->value) . '/' . $jobId;

        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson($dir . '/request.json', $payload);
    }
}
