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

use function preg_quote;

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
    /**
     * A valid response is mapped into death-notice records, preserving the cemetery, notice type and
     * the exact harvested death date.
     */
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
     * A notice whose name is missing/empty-after-trim is dropped (not coerced to an empty name and
     * not aborting the whole response), while a sibling valid notice for the same person is kept: the
     * person ends up with exactly the one usable notice.
     */
    #[Test]
    public function skipsANoticeWithoutAUsableName(): void
    {
        $this->placeResponse('job-1', 'response-namelessnotice.json');
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);

        self::assertCount(1, $byPerson['I1']);
        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
        self::assertSame('Erika Mustermann geb. Mueller', $notice->name);
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
     * Provides the ISO-8601 fetchedAt variants the reader must still accept after the relative-string
     * forms ("now", "yesterday", "+1 week") are rejected: a "Z" zulu suffix and a fractional-second
     * value. Both are real ISO-8601 timestamp forms and must parse into a record.
     *
     * @return array<string, array{0:string}>
     */
    public static function acceptingFetchedAtFixtures(): array
    {
        return [
            'zulu suffix'       => ['response-fetchedat-zulu.json'],
            'fractional second' => ['response-fetchedat-fractional.json'],
        ];
    }

    /**
     * An ISO-8601 fetchedAt with a "Z" suffix or fractional seconds is accepted and mapped into a
     * record, proving the anchored shape guard does not over-reject valid ISO-8601 variants.
     */
    #[Test]
    #[DataProvider('acceptingFetchedAtFixtures')]
    public function acceptsIsoFetchedAtVariants(string $fixture): void
    {
        $this->placeResponse('job-1', $fixture);
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);

        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
    }

    /**
     * Provides the reject fixtures, each paired with a fragment of the message the INTENDED check
     * raises. The cases span the distinct hard-validation gates — unknown schema version,
     * job-ownership boundary, URL scheme allow-list (with a protocol-relative "//evil.host/x" URL
     * that yields a null scheme, strengthening the scheme allow-list boundary) and the required
     * parseable timestamp — so the message assertion proves the right check fired (not just "some
     * validation failed", which a wrong-field reject could satisfy).
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function rejectingFixtures(): array
    {
        return [
            'bad url scheme'        => ['response-bad-url.json', 'scheme is not allowed'],
            'protocol-relative url' => ['response-protocol-relative-url.json', 'scheme is not allowed'],
            'foreign job owner'     => ['response-foreign-job.json', 'person not in the request'],
            'unknown schema'        => ['response-bad-schema.json', 'schema version'],
            'unparseable date'      => ['response-bad-fetchedat.json', 'not a parseable timestamp'],
            'relative now'          => ['response-fetchedat-now.json', 'not a parseable timestamp'],
            'relative yesterday'    => ['response-fetchedat-yesterday.json', 'not a parseable timestamp'],
            'relative offset'       => ['response-fetchedat-relative.json', 'not a parseable timestamp'],
        ];
    }

    /**
     * Each malformed response is rejected with a ResponseValidationException whose message proves the
     * intended hard-validation gate fired.
     */
    #[Test]
    #[DataProvider('rejectingFixtures')]
    public function rejectsAnInvalidResponse(string $fixture, string $expectedMessageFragment): void
    {
        $this->placeResponse('job-1', $fixture);
        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');
        (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);
    }
}
