<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use function array_key_exists;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strlen;

/**
 * Transport-neutral, defensively narrowed view of the untrusted {@see GET /capabilities} response body
 * a finder service returns. A required field that is missing or wrong-typed, a capabilities document
 * that yields no valid portal, or a corrupt notice-field shape collapses the whole document to null so
 * the probe and the admin UI never consume a half-trusted structure.
 *
 * The narrowing is deliberately asymmetric: {@see $schemaVersions} is a display-only field and is
 * TOLERANT (a stray non-int element is noise to drop, not a corrupt document), whereas
 * {@see $noticeFields} is STRICTER (a non-string element signals a corrupt shape and invalidates the
 * whole document; only an UNKNOWN-but-string entry is forward-compatibly dropped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderCapabilities
{
    /**
     * The closed enum of recognised notice fields a finder may advertise; any other string is dropped.
     *
     * @var list<string>
     */
    private const array NOTICE_FIELDS = [
        'death',
        'birth',
        'place',
        'cemetery',
        'age',
        'funeralDate',
        'relatives',
    ];

    /**
     * Constructor.
     *
     * @param string             $finderId         The stable finder identifier (non-empty, ≤128 chars).
     * @param string|null        $finderVersion    The finder version string (≤100 chars) or null.
     * @param int                $retentionSeconds The advertised retention window in seconds (1..31_536_000).
     * @param list<int>          $schemaVersions   The de-duped list of supported schema versions.
     * @param list<FinderPortal> $portals          The non-empty list of narrowed portals.
     * @param list<string>       $noticeFields     The recognised notice fields the finder advertises.
     * @param array<string,bool> $features         The string-keyed boolean feature flags.
     */
    public function __construct(
        public string $finderId,
        public ?string $finderVersion,
        public int $retentionSeconds,
        public array $schemaVersions,
        public array $portals,
        public array $noticeFields,
        public array $features,
    ) {
    }

    /**
     * Narrows an untrusted capabilities response body into a value object, or returns null when the
     * document is invalid (a required key missing/wrong-typed, no valid portal survives, or
     * `noticeFields` is present but not a string list).
     *
     * @param array<int|string, mixed> $body The decoded, untrusted capabilities response body.
     *
     * @return self|null The narrowed capabilities, or null when the document is invalid.
     */
    public static function tryFromArray(array $body): ?self
    {
        $finderId = $body['finderId'] ?? null;

        if (
            !is_string($finderId)
            || ($finderId === '')
            || (mb_strlen($finderId) > 128)
        ) {
            return null;
        }

        $retentionSeconds = $body['retentionSeconds'] ?? null;

        if (
            !is_int($retentionSeconds)
            || ($retentionSeconds < 1)
            || ($retentionSeconds > 31_536_000)
        ) {
            return null;
        }

        $schemaVersions = self::narrowSchemaVersions($body['schemaVersions'] ?? null);

        if ($schemaVersions === null) {
            return null;
        }

        $portals = self::narrowPortals($body['portals'] ?? null);

        if ($portals === null) {
            return null;
        }

        $noticeFields = self::narrowNoticeFields($body);

        if ($noticeFields === null) {
            return null;
        }

        $finderVersion = $body['finderVersion'] ?? null;

        if (
            !is_string($finderVersion)
            || (mb_strlen($finderVersion) > 100)
        ) {
            $finderVersion = null;
        }

        return new self(
            $finderId,
            $finderVersion,
            $retentionSeconds,
            $schemaVersions,
            $portals,
            $noticeFields,
            self::narrowFeatures($body['features'] ?? null),
        );
    }

    /**
     * Narrows the TOLERANT schemaVersions field: drops every non-int and out-of-range element, de-dupes
     * the survivors, and returns null only when no valid version remains.
     *
     * @param mixed $raw The untrusted schemaVersions value.
     *
     * @return list<int>|null The de-duped versions, or null when none survive.
     */
    private static function narrowSchemaVersions(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $versions = [];

        foreach ($raw as $version) {
            if (
                is_int($version)
                && ($version >= 1)
                && ($version <= 1000)
            ) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            return null;
        }

        return array_values(array_unique($versions));
    }

    /**
     * Narrows the portals field: maps each entry through {@see FinderPortal::tryFromArray()}, drops the
     * unusable ones, and returns null when the input is not a non-empty array or no portal survives.
     *
     * @param mixed $raw The untrusted portals value.
     *
     * @return list<FinderPortal>|null The surviving portals, or null when none survive.
     */
    private static function narrowPortals(mixed $raw): ?array
    {
        if (
            !is_array($raw)
            || ($raw === [])
        ) {
            return null;
        }

        $portals = [];

        foreach ($raw as $entry) {
            $portal = FinderPortal::tryFromArray($entry);

            if ($portal instanceof FinderPortal) {
                $portals[] = $portal;
            }
        }

        if ($portals === []) {
            return null;
        }

        return $portals;
    }

    /**
     * Narrows the STRICTER noticeFields field: an absent key yields the empty list, a present value that
     * is not an array or contains a non-string element invalidates the whole document (null), and the
     * surviving strings are filtered down to the recognised closed enum.
     *
     * @param array<int|string, mixed> $body The untrusted capabilities response body.
     *
     * @return list<string>|null The recognised notice fields, or null when the shape is corrupt.
     */
    private static function narrowNoticeFields(array $body): ?array
    {
        if (!array_key_exists('noticeFields', $body)) {
            return [];
        }

        $raw = $body['noticeFields'];

        if (!is_array($raw)) {
            return null;
        }

        $fields = [];

        foreach ($raw as $field) {
            if (!is_string($field)) {
                return null;
            }

            if (in_array($field, self::NOTICE_FIELDS, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Narrows the features field: keeps only entries with a string key and a boolean value, dropping
     * integer-keyed entries and any non-bool value.
     *
     * @param mixed $raw The untrusted features value.
     *
     * @return array<string,bool> The string-keyed boolean feature flags.
     */
    private static function narrowFeatures(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $features = [];

        foreach ($raw as $key => $value) {
            if (
                is_string($key)
                && is_bool($value)
            ) {
                $features[$key] = $value;
            }
        }

        return $features;
    }
}
