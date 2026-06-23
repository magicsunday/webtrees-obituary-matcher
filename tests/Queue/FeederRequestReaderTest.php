<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests the validating reader of the claimed job's request.json: it narrows the trusted-directory
 * payload into the numeric tree id and the requested person ids, and rejects every malformed
 * request (wrong schema version, mismatched job id, non-int tree id and a non-string/empty
 * candidate person id) with a dedicated validation exception.
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
            'schemaVersion' => 2,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [
                ['personId' => 'X1', 'queries' => []],
                ['personId' => 'X2', 'queries' => []],
            ],
            'treeId' => 11,
        ]);

        $result = (new FeederRequestReader(new QueuePaths($this->tmp), 5_242_880))->read('job-1');

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
        (new FeederRequestReader(new QueuePaths($this->tmp), 5_242_880))->read('job-1');
    }

    /**
     * A request whose embedded job id does not match the directory it was read from is rejected.
     */
    #[Test]
    public function rejectsAJobIdThatDoesNotMatchTheDirectory(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 2,
            'jobId'         => 'job-2',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => 'X1', 'queries' => []]],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        (new FeederRequestReader(new QueuePaths($this->tmp), 5_242_880))->read('job-1');
    }

    /**
     * A request whose tree id is not an integer is rejected.
     */
    #[Test]
    public function rejectsANonIntegerTreeId(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 2,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => 'X1', 'queries' => []]],
            'treeId'        => '11',
        ]);

        $this->expectException(ResponseValidationException::class);
        (new FeederRequestReader(new QueuePaths($this->tmp), 5_242_880))->read('job-1');
    }

    /**
     * A request carrying a candidate whose person id is empty (or not a string) is rejected.
     */
    #[Test]
    public function rejectsAnEmptyCandidatePersonId(): void
    {
        $this->seedRequest('job-1', [
            'schemaVersion' => 2,
            'jobId'         => 'job-1',
            'createdAt'     => '2026-06-20T00:00:00+00:00',
            'locale'        => 'de-DE',
            'candidates'    => [['personId' => '', 'queries' => []]],
            'treeId'        => 11,
        ]);

        $this->expectException(ResponseValidationException::class);
        (new FeederRequestReader(new QueuePaths($this->tmp), 5_242_880))->read('job-1');
    }

    /**
     * Writes the given decoded request payload into done/<jobId>/request.json, simulating a claimed
     * job awaiting the drain.
     *
     * @param string               $jobId   The job identifier whose state directory receives the request.
     * @param array<string, mixed> $payload The decoded request payload to write.
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
