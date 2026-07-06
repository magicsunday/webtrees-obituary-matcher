<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Disposition;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Domain\ValidatedResponse;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function preg_quote;
use function reset;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the pure response validator: it narrows an already-decoded (file-free) response payload into
 * death-notice records keyed by person and rejects every malformed payload with a dedicated validation
 * exception — the same contract {@see \MagicSunday\ObituaryMatcher\Queue\RestJobTransport} delegates to.
 * Beyond the inline happy/ownership/schema cases, the fixture-driven cases pin the untrusted-input
 * boundary the REST transport still relies on: the accepted ISO-8601 fetchedAt variants, the numeric
 * person-id ownership coercion, the empty-structured-name fallback and every rejected timestamp shape
 * (relative modifiers, trailing garbage/newline and out-of-range date components that DateTimeImmutable
 * would otherwise roll silently).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ResponseValidator::class)]
#[CoversClass(ResponseValidationException::class)]
#[UsesClass(CoverageStatus::class)]
#[UsesClass(PortalCoverage::class)]
#[UsesClass(ValidatedResponse::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(Disposition::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(ObituaryDateParser::class)]
final class ResponseValidatorTest extends TestCase
{
    /**
     * A minimal valid decoded payload: one person (I1) with a single well-formed notice.
     *
     * @return array<string, mixed> The valid payload.
     */
    private function validPayload(): array
    {
        return $this->payloadWithNotice();
    }

    /**
     * Builds the valid one-person payload, overlaying the given fields onto its single notice so a
     * test can flip one notice field (a bad url, an empty name) without a nested write on a mixed
     * offset the strict ruleset forbids.
     *
     * @param array<string, mixed> $noticeOverrides The notice fields to overlay on the default notice.
     *
     * @return array<string, mixed> The payload carrying the overlaid notice.
     */
    private function payloadWithNotice(array $noticeOverrides = []): array
    {
        $notice = [
            ...$this->minimalNotice(),
            ...$noticeOverrides,
        ];

        return $this->payloadWithResults([
            'I1' => [
                'notices'  => [$notice],
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'ok', 'noticeCount' => 1],
                ],
            ],
        ]);
    }

    /**
     * A minimal well-formed notice used to compose payloads.
     *
     * @return array<string, mixed> The notice.
     */
    private function minimalNotice(): array
    {
        return [
            'url'        => 'https://obituary.example/n/1',
            'fetchedAt'  => '2024-05-21T08:30:00Z',
            'noticeType' => 'obituary',
            'name'       => 'Max Mustermann',
            'source'     => 'obituary-example-de',
        ];
    }

    /**
     * Wraps a raw per-person results map into a schema-versioned response payload for job "job-1".
     *
     * @param array<string, mixed> $results The per-person results map.
     *
     * @return array<string, mixed> The response payload.
     */
    private function payloadWithResults(array $results): array
    {
        return [
            'schemaVersion' => 1,
            'jobId'         => 'job-1',
            'results'       => $results,
        ];
    }

    /**
     * A valid decoded payload is narrowed into death-notice records keyed by person id.
     */
    #[Test]
    public function aValidPayloadYieldsNoticeRecordsKeyedByPerson(): void
    {
        $byPerson = (new ResponseValidator())->validate($this->validPayload(), 'job-1', ['I1'])->notices;
        self::assertArrayHasKey('I1', $byPerson);
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('https://obituary.example/n/1', $byPerson['I1'][0]->url);
    }

    /**
     * The required per-portal coverage is decoded alongside the notices, keyed by person.
     *
     * @return void
     */
    #[Test]
    public function decodesThePerPortalCoverage(): void
    {
        $coverage = (new ResponseValidator())->validate($this->validPayload(), 'job-1', ['I1'])->coverage;

        self::assertArrayHasKey('I1', $coverage);
        self::assertCount(1, $coverage['I1']);

        $portal = $coverage['I1'][0];
        self::assertSame('trauer_anzeigen', $portal->portal);
        self::assertSame(CoverageStatus::Ok, $portal->status);
        self::assertSame(1, $portal->noticeCount);
    }

    /**
     * The legacy shape — a bare notice list per person — is rejected, so a real finder response (which
     * wraps notices in a PersonResult object) can never be silently misread as notices.
     *
     * @return void
     */
    #[Test]
    public function rejectsABareNoticeListResult(): void
    {
        $payload = $this->payloadWithResults(['I1' => [$this->minimalNotice()]]);

        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A PersonResult missing the required coverage is rejected — coverage is the trust anchor that tells
     * a genuine miss from a portal outage, so it must never be optional.
     *
     * @return void
     */
    #[Test]
    public function rejectsAResultWithoutCoverage(): void
    {
        $payload = $this->payloadWithResults(['I1' => ['notices' => [$this->minimalNotice()]]]);

        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A coverage entry whose status is not one of ok|failed|skipped is rejected rather than defaulting to
     * a real status.
     *
     * @return void
     */
    #[Test]
    public function rejectsAnInvalidCoverageStatus(): void
    {
        $payload = $this->payloadWithResults([
            'I1' => [
                'notices'  => [],
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'down'],
                ],
            ],
        ]);

        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A coverage message longer than the contract's maximum is rejected at the untrusted boundary rather
     * than carried through to an eventual operator-facing sink unbounded.
     *
     * @return void
     */
    #[Test]
    public function rejectsAnOverLengthCoverageMessage(): void
    {
        $payload = $this->payloadWithResults([
            'I1' => [
                'notices'  => [],
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'failed', 'message' => str_repeat('x', 1_001)],
                ],
            ],
        ]);

        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * The message limit is character-based (the contract's maxLength is Unicode code points): a message
     * of 1000 two-byte characters (2000 bytes) is within the limit and accepted — a byte-based check
     * would wrongly reject this contract-valid message.
     *
     * @return void
     */
    #[Test]
    public function acceptsAMultibyteCoverageMessageWithinTheCharacterLimit(): void
    {
        $message = str_repeat('ä', 1_000);
        $payload = $this->payloadWithResults([
            'I1' => [
                'notices'  => [],
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'failed', 'message' => $message],
                ],
            ],
        ]);

        $coverage = (new ResponseValidator())->validate($payload, 'job-1', ['I1'])->coverage;

        self::assertSame($message, $coverage['I1'][0]->message);
    }

    /**
     * A present-but-non-string coverage message is rejected, not silently dropped: coverage is decoded
     * fail-closed (like its portal/status/noticeCount siblings), so a malformed message is a malformed
     * response.
     *
     * @return void
     */
    #[Test]
    public function rejectsANonStringCoverageMessage(): void
    {
        $payload = $this->payloadWithResults([
            'I1' => [
                'notices'  => [],
                'coverage' => [
                    ['portal' => 'trauer_anzeigen', 'status' => 'failed', 'message' => ['not', 'a', 'string']],
                ],
            ],
        ]);

        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A payload whose jobId does not match the requested job is rejected.
     */
    #[Test]
    public function aMismatchedJobIdIsRejected(): void
    {
        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($this->validPayload(), 'OTHER-JOB', ['I1']);
    }

    /**
     * A result for a person who was not in the request is rejected at the job-ownership boundary.
     */
    #[Test]
    public function aResultForAnUnrequestedPersonIsRejected(): void
    {
        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($this->validPayload(), 'job-1', ['I2']); // I1 not expected
    }

    /**
     * A payload carrying an unknown schema version is rejected.
     */
    #[Test]
    public function aWrongSchemaVersionIsRejected(): void
    {
        $payload                  = $this->validPayload();
        $payload['schemaVersion'] = 2;
        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A notice whose URL scheme is not in the allow-list is rejected.
     */
    #[Test]
    public function aDisallowedUrlSchemeIsRejected(): void
    {
        $payload = $this->payloadWithNotice(['url' => 'ftp://x/n']);
        $this->expectException(ResponseValidationException::class);
        (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
    }

    /**
     * A notice with no usable name is dropped (not rejected): the person ends with an empty list.
     */
    #[Test]
    public function anEmptyNameNoticeIsDroppedNotRejected(): void
    {
        $payload  = $this->payloadWithNotice(['name' => '   ']);
        $byPerson = (new ResponseValidator())->validate($payload, 'job-1', ['I1'])->notices;
        self::assertSame([], $byPerson['I1']); // dropped, no throw
    }

    /**
     * A notice whose structured `parsedName` is PRESENT but empty (empty surname, no given names) must
     * not yield a useless empty PersonName: the validator falls back to parsing the non-empty raw display
     * name, exactly as it would for an absent structured key.
     *
     * @return void
     */
    #[Test]
    public function anEmptyStructuredNameFallsBackToTheParsedRawName(): void
    {
        $byPerson = (new ResponseValidator())->validate(
            $this->decodeFixture('response-empty-parsedname.json'),
            'job-1',
            ['I1'],
        )->notices;

        $notice = $byPerson['I1'][0];
        self::assertSame('Mustermann', $notice->parsedName->surname);
        self::assertSame(['Erika'], $notice->parsedName->givenNames);
    }

    /**
     * A purely-numeric JSON person key (which json_decode casts to an int) is coerced to string for the
     * ownership check, so a requested numeric XREF is narrowed into a record rather than wrongly rejected.
     *
     * @return void
     */
    #[Test]
    public function aNumericPersonKeyIsAcceptedForItsRequestedNumericXref(): void
    {
        $byPerson = (new ResponseValidator())->validate(
            $this->decodeFixture('response-numeric-personid.json'),
            'job-1',
            ['123'],
        )->notices;

        self::assertCount(1, $byPerson, 'exactly the requested numeric person is present');

        // PHP canonicalises the numeric-string map key to an int, so assert through the notice value
        // rather than the array key type.
        $notices = reset($byPerson);
        self::assertCount(1, $notices);
        self::assertSame('Erika Mustermann geb. Mueller', $notices[0]->name);
    }

    /**
     * A notice carrying `disposition: "cremation"` decodes to Disposition::Cremation, which the confirm
     * write-back later reads to emit a CREM (#62).
     *
     * @return void
     */
    #[Test]
    public function decodesTheCremationDisposition(): void
    {
        $byPerson = (new ResponseValidator())->validate(
            $this->payloadWithNotice(['disposition' => 'cremation']),
            'job-1',
            ['I1'],
        )->notices;

        $notices = reset($byPerson);
        self::assertNotFalse($notices);
        self::assertSame(Disposition::Cremation, $notices[0]->disposition);
    }

    /**
     * A notice with NO disposition defaults to Disposition::Burial (absence means burial), so the confirm
     * writes a BURI — the disposition is never required.
     *
     * @return void
     */
    #[Test]
    public function defaultsToBurialWhenTheNoticeHasNoDisposition(): void
    {
        $byPerson = (new ResponseValidator())->validate($this->validPayload(), 'job-1', ['I1'])->notices;

        $notices = reset($byPerson);
        self::assertNotFalse($notices);
        self::assertSame(Disposition::Burial, $notices[0]->disposition);
    }

    /**
     * A PRESENT-but-unknown disposition string does NOT silently coerce to burial (which would write a
     * wrong BURI for an intended cremation) — the malformed notice is dropped, so the result carries no
     * record for it. Absence still means burial (above); this is the invariant "cremation must never
     * silently fall back to burial".
     *
     * @return void
     */
    #[Test]
    public function dropsANoticeWithAnUnknownDispositionRatherThanDefaultingToBurial(): void
    {
        $byPerson = (new ResponseValidator())->validate(
            $this->payloadWithNotice(['disposition' => 'crematoin']),
            'job-1',
            ['I1'],
        )->notices;

        $notices = reset($byPerson);
        self::assertNotFalse($notices);
        self::assertSame([], $notices, 'a notice with an unknown disposition must be dropped');
    }

    /**
     * A present-but-non-string disposition is likewise a malformed notice and is dropped.
     *
     * @return void
     */
    #[Test]
    public function dropsANoticeWithANonStringDisposition(): void
    {
        $byPerson = (new ResponseValidator())->validate(
            $this->payloadWithNotice(['disposition' => 42]),
            'job-1',
            ['I1'],
        )->notices;

        $notices = reset($byPerson);
        self::assertNotFalse($notices);
        self::assertSame([], $notices, 'a notice with a non-string disposition must be dropped');
    }

    /**
     * The ISO-8601 fetchedAt variants the validator must still accept after the relative-string forms are
     * rejected: a "Z" zulu suffix, a fractional-second value and the three legal timezone-offset forms
     * (colon "+02:00", compact "+0200", hour-only "+02"). All are real ISO-8601 timestamps.
     *
     * @return array<string, array{0: string}> The fixture per accepted variant.
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
     * A valid ISO-8601 fetchedAt variant is accepted and narrowed into a record, proving the anchored
     * timestamp guard does not over-reject legal ISO-8601 forms (a rejected variant would throw).
     *
     * @param string $fixture The accepted-variant fixture.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('acceptingFetchedAtFixtures')]
    public function anIsoFetchedAtVariantIsAccepted(string $fixture): void
    {
        $byPerson = (new ResponseValidator())->validate($this->decodeFixture($fixture), 'job-1', ['I1'])->notices;

        self::assertCount(1, $byPerson['I1']);
    }

    /**
     * The reject fixtures, each paired with a fragment of the message the INTENDED gate raises, so the
     * assertion proves the right check fired: the URL scheme allow-list (a protocol-relative `//host/x`
     * yields a null scheme), the numeric job-ownership boundary, and the required parseable timestamp —
     * including relative modifiers, an ISO-8601 prefix with trailing garbage/modifier/newline that an
     * unanchored check would let DateTimeImmutable silently apply, and an out-of-range component ("00"
     * month/day) that DateTimeImmutable would otherwise roll backward instead of throwing.
     *
     * @return array<string, array{0: string, 1: string}> The fixture and expected message fragment.
     */
    public static function rejectingFixtures(): array
    {
        return [
            'protocol-relative url' => ['response-protocol-relative-url.json', 'scheme is not allowed'],
            'foreign numeric owner' => ['response-numeric-foreign.json', 'person not in the request'],
            'unparseable date'      => ['response-bad-fetchedat.json', 'not a parseable timestamp'],
            'relative now'          => ['response-fetchedat-now.json', 'not a parseable timestamp'],
            'relative yesterday'    => ['response-fetchedat-yesterday.json', 'not a parseable timestamp'],
            'relative offset'       => ['response-fetchedat-relative.json', 'not a parseable timestamp'],
            'trailing modifier'     => ['response-fetchedat-trailing-modifier.json', 'not a parseable timestamp'],
            'trailing word'         => ['response-fetchedat-trailing-word.json', 'not a parseable timestamp'],
            'trailing garbage'      => ['response-fetchedat-trailing-garbage.json', 'not a parseable timestamp'],
            'trailing newline'      => ['response-fetchedat-trailing-newline.json', 'not a parseable timestamp'],
            'zero month and day'    => ['response-fetchedat-zeromonthday.json', 'out-of-range date component'],
            'zero day'              => ['response-fetchedat-zeroday.json', 'out-of-range date component'],
            'zero month'            => ['response-fetchedat-zeromonth.json', 'out-of-range date component'],
        ];
    }

    /**
     * Each malformed response is rejected with a {@see ResponseValidationException} whose message proves
     * the intended hard-validation gate fired (not merely "some validation failed").
     *
     * @param string $fixture                 The malformed-response fixture.
     * @param string $expectedMessageFragment A fragment of the message the intended gate raises.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('rejectingFixtures')]
    public function aMalformedResponseIsRejectedWithItsGateMessage(string $fixture, string $expectedMessageFragment): void
    {
        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');

        (new ResponseValidator())->validate($this->decodeFixture($fixture), 'job-1', ['I1']);
    }

    /**
     * Decodes a response fixture from `tests/fixtures` into the associative payload the validator narrows.
     * The validator never touches the filesystem — the fixture is only the payload SOURCE, so the
     * file-free contract holds — but the fixtures pin the exact untrusted shapes (ISO-8601 timestamp
     * variants, out-of-range components, a numeric person key) a hand-inlined array would transcribe
     * imprecisely.
     *
     * @param string $fixture The fixture file name under `tests/fixtures`.
     *
     * @return array<array-key, mixed> The decoded payload.
     */
    private function decodeFixture(string $fixture): array
    {
        $json = file_get_contents(__DIR__ . '/../fixtures/' . $fixture);
        self::assertIsString($json, 'Fixture not readable: ' . $fixture);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
