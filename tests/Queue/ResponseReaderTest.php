<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function dirname;
use function file_get_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function preg_quote;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the untrusted response reader: it maps a valid response into death-notice records and
 * rejects every malformed response (bad URL scheme, foreign job ownership, unknown schema version
 * and an unparseable timestamp) with a dedicated validation exception.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ResponseReader::class)]
#[CoversClass(ResponseValidationException::class)]
#[UsesClass(QueuePaths::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryDateParser::class)]
final class ResponseReaderTest extends TempDirTestCase
{
    #[Test]
    public function readsAndMapsAValidResponse(): void
    {
        $this->placeResponse('job-1', 'response-valid.json');
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);

        self::assertArrayHasKey('I1', $byPerson);
        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
        self::assertSame('Waldfriedhof Musterstadt', $notice->cemetery?->name);
        self::assertSame(NoticeType::Obituary, $notice->noticeType);
        self::assertTrue($notice->death->isExact());
    }

    /**
     * Returns the first decoded notice for a person as a mixed value, so the calling test asserts
     * the concrete record type itself rather than trusting the reader's declared return type.
     *
     * @param array<string, list<DeathNoticeRecord>> $byPerson The reader output.
     * @param string                                 $personId The person whose first notice is read.
     *
     * @return mixed The first notice for that person.
     */
    private static function firstNotice(array $byPerson, string $personId): mixed
    {
        return $byPerson[$personId][0];
    }

    /**
     * Provides the reject fixtures, each paired with a fragment of the message the INTENDED check
     * raises. The four cases span the four distinct hard-validation gates — unknown schema version,
     * job-ownership boundary, URL scheme allow-list and the required parseable timestamp — one
     * fixture per gate, so the message assertion proves the right check fired (not just "some
     * validation failed", which a wrong-field reject could satisfy).
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function rejectingFixtures(): array
    {
        return [
            'bad url scheme'    => ['response-bad-url.json', 'scheme is not allowed'],
            'foreign job owner' => ['response-foreign-job.json', 'person not in the request'],
            'unknown schema'    => ['response-bad-schema.json', 'schema version'],
            'unparseable date'  => ['response-bad-fetchedat.json', 'not a parseable timestamp'],
        ];
    }

    #[Test]
    #[DataProvider('rejectingFixtures')]
    public function rejectsAnInvalidResponse(string $fixture, string $expectedMessageFragment): void
    {
        $this->placeResponse('job-1', $fixture);
        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');
        (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);
    }

    /**
     * Writes the decoded fixture bytes into done/<jobId>/response.json, simulating the feeder's
     * final state after a successful scrape.
     *
     * @param string $jobId   The job identifier whose done directory receives the response.
     * @param string $fixture The fixture file name under tests/fixtures.
     *
     * @return void
     */
    private function placeResponse(string $jobId, string $fixture): void
    {
        $path = (new QueuePaths($this->tmp))->doneDir($jobId) . '/response.json';

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/' . $fixture),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        AtomicFile::writeJson($path, $data);
    }
}
