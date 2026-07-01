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
use MagicSunday\ObituaryMatcher\Queue\ResponseValidationException;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure response validator: it narrows an already-decoded (file-free) response payload into
 * death-notice records keyed by person and rejects every malformed payload (bad URL scheme, foreign
 * job ownership, unknown schema version) with a dedicated validation exception — the same contract
 * {@see \MagicSunday\ObituaryMatcher\Queue\RestJobTransport} delegates to.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ResponseValidator::class)]
#[CoversClass(ResponseValidationException::class)]
#[UsesClass(DeathNoticeRecord::class)]
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
            'url'        => 'https://obituary.example/n/1',
            'fetchedAt'  => '2024-05-21T08:30:00Z',
            'noticeType' => 'obituary',
            'name'       => 'Max Mustermann',
            'source'     => 'obituary-example-de',
            ...$noticeOverrides,
        ];

        return [
            'schemaVersion' => 1,
            'jobId'         => 'job-1',
            'results'       => [
                'I1' => [$notice],
            ],
        ];
    }

    /**
     * A valid decoded payload is narrowed into death-notice records keyed by person id.
     */
    #[Test]
    public function aValidPayloadYieldsNoticeRecordsKeyedByPerson(): void
    {
        $byPerson = (new ResponseValidator())->validate($this->validPayload(), 'job-1', ['I1']);
        self::assertArrayHasKey('I1', $byPerson);
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('https://obituary.example/n/1', $byPerson['I1'][0]->url);
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
        $byPerson = (new ResponseValidator())->validate($payload, 'job-1', ['I1']);
        self::assertSame([], $byPerson['I1']); // dropped, no throw
    }
}
