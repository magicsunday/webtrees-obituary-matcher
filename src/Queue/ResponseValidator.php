<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use DateTimeImmutable;
use Exception;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;

use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function sprintf;
use function strtolower;
use function substr;
use function trim;

use const PHP_URL_SCHEME;

/**
 * Validates and decodes the UNTRUSTED, already-decoded response payload a finder produced (read from a
 * REST transport body) into death-notice records keyed by the personId they belong to. The payload is scraped from external obituary sites, so NOTHING in it is
 * trusted: it is narrowed field by field with is_* guards before any value object is constructed, and
 * any failure throws {@see ResponseValidationException} (distinct from a plain IO failure of whatever
 * fetched the payload). This is the transport-free core {@see RestJobTransport} delegates to once it has
 * read and JSON-decoded the response body, so the same validation holds for every transport.
 *
 * The decoded response shape this validator narrows:
 *
 *     {
 *         "jobId": string,                 // must equal the requested job id
 *         "schemaVersion": int,            // must equal self::SCHEMA_VERSION
 *         "results": {                     // map of personId => list of notices
 *             "<personId>": [              // personId must be one of the expected (requested) ids
 *                 {
 *                     "noticeType": string,        // mapped via NoticeType::fromStringOrDefault
 *                     "name": string,              // raw display name
 *                     "birth": string,             // optional, parsed via ObituaryDateParser
 *                     "death": string,             // optional, parsed via ObituaryDateParser
 *                     "place": string,             // optional, non-empty string => Place
 *                     "cemetery": string,          // optional, non-empty string => Place
 *                     "age": int,                  // optional
 *                     "funeralDate": string,       // optional, parsed via ObituaryDateParser
 *                     "relatives": [               // optional, malformed entries skipped
 *                         {"name": string, "relationGuess": string, "confidence": float}
 *                     ],
 *                     "url": string,               // scheme must be http or https
 *                     "source": string,            // source/portal identifier
 *                     "fetchedAt": string          // required, ISO-8601 parseable
 *                 }
 *             ]
 *         }
 *     }
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ResponseValidator
{
    /**
     * @var int The only response schema version this validator accepts. An unknown version is rejected.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * @var list<string> The URL schemes a notice URL is allowed to carry (everything else rejected).
     */
    private const array ALLOWED_URL_SCHEMES = ['http', 'https'];

    /**
     * Validates and decodes the already-decoded response payload for the given job into death-notice
     * records, keyed by the personId they belong to.
     *
     * @param array<int|string, mixed> $payload           The decoded response payload to validate.
     * @param string                   $jobId             The job whose response is validated.
     * @param list<string>             $expectedPersonIds The person ids that were in the request for
     *                                                    this job (the job-ownership boundary; a result
     *                                                    for any other id is rejected).
     *
     * @return array<string, list<DeathNoticeRecord>> The decoded notices keyed by personId.
     *
     * @throws ResponseValidationException When the payload fails any validation check.
     */
    public function validate(array $payload, string $jobId, array $expectedPersonIds): array
    {
        if (($payload['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new ResponseValidationException('Unknown or missing response schema version.');
        }

        if (($payload['jobId'] ?? null) !== $jobId) {
            throw new ResponseValidationException('Response jobId does not match the requested job.');
        }

        $results = $payload['results'] ?? null;

        if (!is_array($results)) {
            throw new ResponseValidationException('Response results is missing or not an object.');
        }

        $byPerson = [];

        foreach ($results as $personId => $notices) {
            // json_decode casts a purely-numeric JSON object key (a GEDCOM-legal numeric XREF) to an
            // int, so coerce the key to string once: a JSON key is always int|string and the
            // ownership in_array is the real gate, not a key type check.
            $personIdString = (string) $personId;

            if (!in_array($personIdString, $expectedPersonIds, true)) {
                throw new ResponseValidationException(
                    'Response contains a result for a person not in the request.'
                );
            }

            if (!is_array($notices)) {
                throw new ResponseValidationException('Response notices for a person is not a list.');
            }

            $records = [];

            foreach ($notices as $notice) {
                if (!is_array($notice)) {
                    throw new ResponseValidationException('Response notice is not an object.');
                }

                $record = $this->decodeNotice($notice);

                // A notice with no usable name is dropped, not coerced or thrown: a single bad
                // notice must not abort the whole response (consistent with the relatives skip).
                if ($record instanceof DeathNoticeRecord) {
                    $records[] = $record;
                }
            }

            $byPerson[$personIdString] = $records;
        }

        return $byPerson;
    }

    /**
     * Validates a single untrusted notice and decodes it into a death-notice record. Every field is
     * narrowed with an is_* guard before it reaches a value object. A notice with no usable name
     * (missing, non-string or empty after trimming) yields null so the caller can drop it, since an
     * empty-name record is useless to the scorer.
     *
     * @param array<int|string, mixed> $notice The untrusted notice payload.
     *
     * @return DeathNoticeRecord|null The decoded record, or null when the notice has no usable name.
     *
     * @throws ResponseValidationException When the notice fails any validation check.
     */
    private function decodeNotice(array $notice): ?DeathNoticeRecord
    {
        $url = $notice['url'] ?? null;

        if (!is_string($url)) {
            throw new ResponseValidationException('Notice url is missing or not a string.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (
            !is_string($scheme)
            || !in_array(strtolower($scheme), self::ALLOWED_URL_SCHEMES, true)
        ) {
            throw new ResponseValidationException(
                sprintf('Notice url scheme is not allowed: %s', $url)
            );
        }

        $fetchedAt = $this->parseFetchedAt($notice['fetchedAt'] ?? null);

        $noticeTypeRaw = $notice['noticeType'] ?? null;
        $noticeType    = is_string($noticeTypeRaw)
            ? NoticeType::fromStringOrDefault($noticeTypeRaw)
            : NoticeType::Obituary;

        $rawName = $notice['name'] ?? null;

        if (
            !is_string($rawName)
            || (trim($rawName) === '')
        ) {
            return null;
        }

        $name = $rawName;

        $source = is_string($notice['source'] ?? null) ? $notice['source'] : '';

        $age = is_int($notice['age'] ?? null) ? $notice['age'] : null;

        return new DeathNoticeRecord(
            $noticeType,
            $name,
            $this->parseName($notice, $name),
            ObituaryDateParser::parse($this->optionalString($notice, 'birth')),
            ObituaryDateParser::parse($this->optionalString($notice, 'death')),
            $this->optionalPlace($notice, 'place'),
            $this->optionalPlace($notice, 'cemetery'),
            $age,
            ObituaryDateParser::parse($this->optionalString($notice, 'funeralDate')),
            $this->decodeRelatives($notice['relatives'] ?? null),
            $url,
            $source,
            $fetchedAt,
        );
    }

    /**
     * Validates and parses the required fetchedAt timestamp into an immutable date-time.
     *
     * @param mixed $raw The untrusted fetchedAt value.
     *
     * @return DateTimeImmutable The parsed timestamp.
     *
     * @throws ResponseValidationException When the value is absent, not a string, not an anchored
     *                                     ISO-8601 date-time, carries an out-of-range date component
     *                                     (a silently rolled-over "00" month/day) or is otherwise
     *                                     unparseable.
     */
    private function parseFetchedAt(mixed $raw): DateTimeImmutable
    {
        if (!is_string($raw)) {
            throw new ResponseValidationException('Notice fetchedAt is missing or not a string.');
        }

        // Require a FULLY end-anchored ISO-8601 date-time before parsing so a relative string the
        // DateTimeImmutable constructor accepts leniently ("now", "yesterday", "+1 week", "noon")
        // is rejected at the untrusted boundary. The end anchor is essential: an unanchored prefix
        // would let trailing content ("...T10:00:00 +1 week") through, and DateTimeImmutable would
        // then APPLY the relative modifier, silently shifting the timestamp. The `D`
        // (PCRE_DOLLAR_ENDONLY) modifier anchors `$` to the true subject end, so a value with a
        // trailing newline ("...T10:00:00Z\n") cannot slip past the guard either. Every real ISO-8601
        // form still passes through: optional fractional seconds and either a "Z" zulu suffix or a
        // numeric offset whose hours are required and whose minutes are optional ("+02", "+02:00",
        // "+0200"). Keeping the minutes optional does not loosen the anti-garbage guard, as the offset
        // stays fully end-anchored.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/D', $raw) !== 1) {
            throw new ResponseValidationException(
                sprintf('Notice fetchedAt is not a parseable timestamp: %s', $raw)
            );
        }

        try {
            $parsed = new DateTimeImmutable($raw);
        } catch (Exception) {
            throw new ResponseValidationException(
                sprintf('Notice fetchedAt is not a parseable timestamp: %s', $raw)
            );
        }

        // Reject a silent roll-over: the digit-count regex permits an out-of-range component (a "00"
        // month or day), which DateTimeImmutable rolls BACKWARD instead of rejecting ("2024-00-00" →
        // 2023-11-30). The parsed value must reproduce the input's date part; if it does not, an
        // out-of-range component was silently shifted. The substr is safe because the regex already
        // guaranteed "\d{4}-\d{2}-\d{2}" as the first ten characters.
        if ($parsed->format('Y-m-d') !== substr($raw, 0, 10)) {
            throw new ResponseValidationException(
                sprintf('Notice fetchedAt has an out-of-range date component: %s', $raw)
            );
        }

        return $parsed;
    }

    /**
     * Returns the parsed name, preferring a structured "parsedName" key when present and well-formed,
     * else falling back to parsing the raw display name.
     *
     * @param array<int|string, mixed> $notice The untrusted notice payload.
     * @param string                   $name   The raw display name.
     *
     * @return PersonName The parsed name parts.
     */
    private function parseName(array $notice, string $name): PersonName
    {
        $structured = $notice['parsedName'] ?? null;

        if (is_array($structured)) {
            $surname = is_string($structured['surname'] ?? null) ? $structured['surname'] : '';

            $given = [];

            if (is_array($structured['givenNames'] ?? null)) {
                foreach ($structured['givenNames'] as $givenName) {
                    if (is_string($givenName)) {
                        $given[] = $givenName;
                    }
                }
            }

            $birthSurname = is_string($structured['birthSurname'] ?? null)
                ? $structured['birthSurname']
                : null;

            // Only use the structured name when it actually carries content: a present-but-empty
            // parsedName (empty surname AND no given names) would otherwise yield a useless empty
            // PersonName even though the validated raw display name is non-empty. Fall through to
            // parsing the raw name in that case, exactly as for an absent structured key.
            if (
                ($surname !== '')
                || ($given !== [])
            ) {
                return new PersonName($given, null, $surname, $birthSurname);
            }
        }

        return ObituaryNameParser::parse($name);
    }

    /**
     * Decodes the relatives list, skipping any entry that is not a well-formed relative object.
     *
     * @param mixed $raw The untrusted relatives value.
     *
     * @return list<NoticeRelative> The decoded relatives in source order.
     */
    private function decodeRelatives(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $relatives = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name          = $entry['name'] ?? null;
            $relationGuess = $entry['relationGuess'] ?? null;
            $confidence    = $entry['confidence'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            if (!is_string($relationGuess)) {
                continue;
            }

            if (
                !is_float($confidence)
                && !is_int($confidence)
            ) {
                continue;
            }

            $relatives[] = new NoticeRelative($name, $relationGuess, (float) $confidence);
        }

        return $relatives;
    }

    /**
     * Returns a Place built from a non-empty string at the given key, or null otherwise.
     *
     * @param array<int|string, mixed> $notice The untrusted notice payload.
     * @param string                   $key    The key to read.
     *
     * @return Place|null The place, or null when the key is absent, not a string or empty.
     */
    private function optionalPlace(array $notice, string $key): ?Place
    {
        $value = $notice[$key] ?? null;

        if (
            is_string($value)
            && ($value !== '')
        ) {
            return new Place($value);
        }

        return null;
    }

    /**
     * Returns the string value at the given key, or null when it is absent or not a string.
     *
     * @param array<int|string, mixed> $notice The untrusted notice payload.
     * @param string                   $key    The key to read.
     *
     * @return string|null The string value, or null.
     */
    private function optionalString(array $notice, string $key): ?string
    {
        $value = $notice[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
