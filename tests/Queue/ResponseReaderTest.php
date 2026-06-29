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
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function preg_quote;
use function reset;

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
#[UsesClass(JobState::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryDateParser::class)]
final class ResponseReaderTest extends QueueTempDirTestCase
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
     * The reader reads a response from a CLAIMED (ingesting) job directory when passed
     * {@see JobState::Ingesting}, so the module can read a response out of the dir it atomically
     * claimed for ingest rather than the unclaimed done dir. The default-{@see JobState::Done} read
     * (every existing caller) keeps reading from done/, proving the new parameter is additive.
     */
    #[Test]
    public function readsAResponseFromAClaimedIngestingDirectory(): void
    {
        $this->placeResponse('job-1', 'response-valid.json', JobState::Ingesting);
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1'], JobState::Ingesting);

        self::assertArrayHasKey('I1', $byPerson);
        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
        self::assertSame('Erika Mustermann geb. Mueller', $notice->name);
    }

    /**
     * The $fromState parameter actually ROUTES the read: with a DIFFERENT valid response in both
     * done/ and ingesting/, a read passed {@see JobState::Ingesting} returns the ingesting fixture's
     * notice, not the done one. This discriminates the routing the single-state ingesting test cannot:
     * seeding only ingesting/ would pass even if the parameter were ignored and the reader always read
     * done/ (it would then find nothing and fail differently), so the two divergent seeds prove the
     * parameter selects the source directory.
     */
    #[Test]
    public function readRoutesToTheStateDirectoryNamedByFromState(): void
    {
        $this->placeResponse('job-1', 'response-valid.json', JobState::Done);
        $this->placeResponse('job-1', 'response-valid-ingesting.json', JobState::Ingesting);

        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1'], JobState::Ingesting);

        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
        // The ingesting fixture's name, not the done fixture's "Erika Mustermann geb. Mueller".
        self::assertSame('Hans Beispiel geb. Schmidt', $notice->name);
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
     * A notice carrying a structured "parsedName" that is PRESENT but empty (an empty surname and no
     * given names) must not yield a useless empty PersonName: the reader falls back to parsing the
     * non-empty raw display name, exactly as it would for an absent structured key. So the resulting
     * record's parsedName carries the surname and given name parsed from "Erika Mustermann", not an
     * empty name.
     */
    #[Test]
    public function fallsBackToTheRawNameWhenTheStructuredNameIsEmpty(): void
    {
        $this->placeResponse('job-1', 'response-empty-parsedname.json');
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['I1']);

        $notice = self::firstNotice($byPerson, 'I1');
        self::assertInstanceOf(DeathNoticeRecord::class, $notice);
        self::assertSame('Mustermann', $notice->parsedName->surname);
        self::assertSame(['Erika'], $notice->parsedName->givenNames);
    }

    /**
     * A purely-numeric JSON person key (which json_decode casts to an int) is coerced to string for
     * the ownership check, so a requested numeric XREF is read into a record rather than wrongly
     * rejected. PHP canonicalises a numeric-string array key back to an int, so the returned key is
     * read back as its string form before the lookup.
     */
    #[Test]
    public function readsAResponseKeyedByANumericPersonId(): void
    {
        $this->placeResponse('job-1', 'response-numeric-personid.json');
        $byPerson = (new ResponseReader(new QueuePaths($this->tmp)))->read('job-1', ['123']);

        self::assertCount(1, $byPerson, 'exactly the requested numeric person is present');

        // The ownership check coerces the int key json_decode produced back to '123', so the notice
        // is read rather than dropped. (PHP canonicalises the numeric-string map key to an int at
        // runtime, so the person is asserted through the notice value, not the array key type.)
        $notices = self::onlyNoticeList($byPerson);
        self::assertCount(1, $notices);
        self::assertSame('Erika Mustermann geb. Mueller', $notices[0]->name);
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
     * Returns the single person's notice list from a one-person reader output, sidestepping the
     * map key type (PHP canonicalises a numeric-string person key to an int at runtime).
     *
     * @param array<string, list<DeathNoticeRecord>> $byPerson The reader output (exactly one person).
     *
     * @return list<DeathNoticeRecord> The notice list of the only person.
     */
    private static function onlyNoticeList(array $byPerson): array
    {
        $notices = reset($byPerson);

        return ($notices === false) ? [] : $notices;
    }

    /**
     * Provides the ISO-8601 fetchedAt variants the reader must still accept after the relative-string
     * forms ("now", "yesterday", "+1 week") are rejected: a "Z" zulu suffix, a fractional-second value
     * and the three legal timezone-offset forms — the colon "+02:00", the compact "+0200" and the
     * hour-only "+02". All are real ISO-8601 timestamp forms and must parse into a record.
     *
     * @return array<string, array{0:string}>
     */
    public static function acceptingFetchedAtFixtures(): array
    {
        return [
            'zulu suffix'       => ['response-fetchedat-zulu.json'],
            'fractional second' => ['response-fetchedat-fractional.json'],
            'colon offset'      => ['response-fetchedat-colon-offset.json'],
            'compact offset'    => ['response-fetchedat-compact-offset.json'],
            'hour-only offset'  => ['response-fetchedat-hour-offset.json'],
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
            'foreign numeric owner' => ['response-numeric-foreign.json', 'person not in the request'],
            'unknown schema'        => ['response-bad-schema.json', 'schema version'],
            'unparseable date'      => ['response-bad-fetchedat.json', 'not a parseable timestamp'],
            'relative now'          => ['response-fetchedat-now.json', 'not a parseable timestamp'],
            'relative yesterday'    => ['response-fetchedat-yesterday.json', 'not a parseable timestamp'],
            'relative offset'       => ['response-fetchedat-relative.json', 'not a parseable timestamp'],
            // An ISO-8601 prefix followed by a trailing relative modifier or garbage must be rejected:
            // an unanchored prefix check would let "...T10:00:00 +1 week" through and DateTimeImmutable
            // would then APPLY the modifier, silently shifting the timestamp.
            'trailing modifier' => ['response-fetchedat-trailing-modifier.json', 'not a parseable timestamp'],
            'trailing word'     => ['response-fetchedat-trailing-word.json', 'not a parseable timestamp'],
            'trailing garbage'  => ['response-fetchedat-trailing-garbage.json', 'not a parseable timestamp'],
            // A trailing newline must be rejected too: DateTimeImmutable parses "...Z\n" leniently
            // (ignoring the newline), so without the regex's `D` (PCRE_DOLLAR_ENDONLY) anchor the
            // garbage value would slip through the guard the anchored pattern exists to enforce.
            'trailing newline' => ['response-fetchedat-trailing-newline.json', 'not a parseable timestamp'],
            // An out-of-range component (a "00" month and/or day) passes the digit-count regex but the
            // DateTimeImmutable constructor SILENTLY ROLLS IT BACKWARD ("2024-00-00" → 2023-11-30)
            // instead of throwing, so it must be rejected by the post-parse date-part reproduction check
            // — the exact silent timestamp shift the anchored regex was meant to prevent.
            'zero month and day' => ['response-fetchedat-zeromonthday.json', 'out-of-range date component'],
            'zero day'           => ['response-fetchedat-zeroday.json', 'out-of-range date component'],
            'zero month'         => ['response-fetchedat-zeromonth.json', 'out-of-range date component'],
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
