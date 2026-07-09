<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The pure, webtrees-free write counterpart of {@see FinderConnectionResolver::listFromConfig()}: it turns
 * the additional-finder rows submitted by the control panel (§5.2f increment 2) into the canonical
 * `finder_additional` JSON the resolver reads back. It applies the same base-URL identity rule the read
 * path dedups on ({@see FinderConnection::baseUrlKeyFor()}) so the write and the read can never drift.
 *
 * The write is STRICT and ALL-OR-NOTHING: every non-blank row is validated at the single
 * {@see FinderConnection::rest()} source, and the first invalid row (bad base URL, control-character
 * token) or duplicate identity throws — nothing is emitted, so the caller persists nothing on a partial
 * failure, matching the single-finder both-or-neither contract. A row with a blank base URL is an unused
 * "add finder" row and is skipped rather than rejected. The token is resolved per row under a
 * REMOVE-wins / typed-wins / keep-by-identity precedence: an explicit remove clears it, a typed token
 * sets it, and a blank field keeps the token already stored for the SAME finder (matched by base-URL
 * identity) so a settings save that does not re-enter the secret does not wipe it. The token VALUE never
 * leaves this class except into the emitted JSON; the token-bearing parameters are {@see SensitiveParameter}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class AdditionalFindersEditor
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Turns the submitted additional-finder rows into the canonical `finder_additional` JSON, or the
     * empty string when no finder remains (all rows blank). Each non-blank row is validated and its token
     * resolved; the first invalid or duplicate row throws so a partial save is never persisted.
     *
     * @param list<array{baseUrl: string, token: string, active: bool, removeToken: bool}> $submitted    The submitted rows.
     * @param string                                                                       $existingJson The persisted `finder_additional` JSON, for token-keep-by-identity.
     * @param list<string>                                                                 $reservedKeys The base-URL identity keys already taken (e.g. the primary connection's), rejected as duplicates.
     *
     * @return string The canonical `finder_additional` JSON, or an empty string when no finder remains.
     *
     * @throws InvalidArgumentException When a row has an invalid base URL / token, duplicates another
     *                                  row's or a reserved base-URL identity, or the result cannot be
     *                                  encoded; the 1-based row position is named in the message.
     */
    public static function toJson(
        #[SensitiveParameter]
        array $submitted,
        #[SensitiveParameter]
        string $existingJson,
        array $reservedKeys = [],
    ): string {
        $storedTokens = self::storedTokensByKey($existingJson);

        $seen = [];

        foreach ($reservedKeys as $reservedKey) {
            $seen[$reservedKey] = true;
        }

        $rows     = [];
        $position = 0;

        foreach ($submitted as $submittedRow) {
            ++$position;

            $baseUrl = $submittedRow['baseUrl'];

            // A blank base URL is an unused "add finder" row — skip it rather than reject the save.
            if ($baseUrl === '') {
                continue;
            }

            $key = FinderConnection::baseUrlKeyFor($baseUrl);

            if (isset($seen[$key])) {
                throw new InvalidArgumentException(
                    sprintf('Additional finder %d duplicates an already configured finder base URL.', $position),
                );
            }

            // Resolve the token: an explicit remove clears it, a typed token sets it, and a blank field
            // keeps the token stored for the SAME finder (matched by base-URL identity), or null when none.
            if ($submittedRow['removeToken']) {
                $token = null;
            } elseif ($submittedRow['token'] !== '') {
                $token = $submittedRow['token'];
            } else {
                $token = $storedTokens[$key] ?? null;
            }

            try {
                $connection = FinderConnection::rest($baseUrl, $token);
            } catch (InvalidArgumentException $exception) {
                // The specific FinderConnection::rest() failure is chained as `previous` for debugging; the
                // thrown message stays a fixed, position-only category. The sole caller (the control-panel
                // save) shows its own generic translated flash and does not surface this message, so a
                // per-reason (and translatable) message is not warranted for this rare invalid-row case.
                throw new InvalidArgumentException(
                    sprintf('Additional finder %d is not a valid connection.', $position),
                    0,
                    $exception,
                );
            }

            $seen[$key] = true;

            $row = [
                'baseUrl' => $connection->baseUrl(),
                'active'  => $submittedRow['active'],
            ];

            $rowToken = $connection->token();

            // Omit the token key when there is no token, so the resolver reads it back as "no token"
            // and no null/empty placeholder lingers in the stored preference.
            if ($rowToken !== null) {
                $row['token'] = $rowToken;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return '';
        }

        try {
            return json_encode($rows, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // A row survived FinderConnection::rest() but carries a byte sequence json_encode rejects
            // (e.g. an invalid-UTF-8 token). Fail the whole save rather than emit a corrupt preference.
            throw new InvalidArgumentException('The additional finders could not be encoded.', 0, $exception);
        }
    }

    /**
     * Projects the persisted `finder_additional` JSON into the display rows the control panel re-renders:
     * EVERY stored finder (active and inactive), each as its base URL, whether a token is set (the value
     * is never exposed) and its active flag. This is the read-back counterpart of {@see self::toJson()} —
     * unlike {@see FinderConnectionResolver::listFromConfig()} (which keeps only the ACTIVE, valid
     * connections for the fan-out), the panel must show every configured finder so the admin can edit or
     * re-activate it. The token VALUE never leaves this method; only a `tokenIsSet` boolean does.
     *
     * @param string $json The persisted `finder_additional` JSON, or an empty string.
     *
     * @return list<array{baseUrl: string, tokenIsSet: bool, active: bool}> The stored finders for display.
     */
    public static function storedRows(#[SensitiveParameter] string $json): array
    {
        $rows = [];

        foreach (self::decodeStored($json) as $row) {
            $rows[] = [
                'baseUrl'    => $row['baseUrl'],
                'tokenIsSet' => $row['token'] !== '',
                'active'     => $row['active'],
            ];
        }

        return $rows;
    }

    /**
     * Returns the token stored for the additional finder whose base URL matches the given one by identity,
     * or null when no such finder is configured or it carries no token. The control panel uses this to
     * probe an EXISTING additional finder with ITS OWN stored token when the admin does not re-enter the
     * secret, rather than falling back to the primary finder's token. The token VALUE never leaves this
     * method except as the return value (consumed only as the probe's Authorization header).
     *
     * @param string $json    The persisted `finder_additional` JSON.
     * @param string $baseUrl The base URL whose stored token to resolve.
     *
     * @return string|null The stored token for that finder, or null when none applies.
     */
    public static function storedTokenFor(
        #[SensitiveParameter]
        string $json,
        string $baseUrl,
    ): ?string {
        return self::storedTokensByKey($json)[FinderConnection::baseUrlKeyFor($baseUrl)] ?? null;
    }

    /**
     * Builds the base-URL-identity → token map from the persisted `finder_additional` JSON, so a blank
     * token field can keep the token already stored for that finder. Only rows carrying a non-empty token
     * contribute.
     *
     * @param string $existingJson The persisted `finder_additional` JSON, or an empty string.
     *
     * @return array<string, string> The base-URL identity key mapped to its stored token.
     */
    private static function storedTokensByKey(
        #[SensitiveParameter]
        string $existingJson,
    ): array {
        $tokens = [];

        foreach (self::decodeStored($existingJson) as $row) {
            if ($row['token'] === '') {
                continue;
            }

            $tokens[FinderConnection::baseUrlKeyFor($row['baseUrl'])] = $row['token'];
        }

        return $tokens;
    }

    /**
     * Defensively decodes the persisted `finder_additional` JSON into narrowed rows — the single shared
     * decode both {@see self::storedRows()} and {@see self::storedTokensByKey()} build on. Invalid JSON, a
     * non-list document, a non-object row, or a row without a non-empty string base URL is dropped
     * (mirroring the resolver's tolerant decode), so a corrupt preference never crashes a save or render.
     * A missing/non-string token narrows to an empty string; the active flag is strict `=== true`.
     *
     * @param string $json The persisted `finder_additional` JSON, or an empty string.
     *
     * @return list<array{baseUrl: string, token: string, active: bool}> The narrowed stored rows.
     */
    private static function decodeStored(#[SensitiveParameter] string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $baseUrl = $row['baseUrl'] ?? null;

            if (!is_string($baseUrl)) {
                continue;
            }

            if ($baseUrl === '') {
                continue;
            }

            $token = $row['token'] ?? null;

            $rows[] = [
                'baseUrl' => $baseUrl,
                'token'   => is_string($token) ? $token : '',
                'active'  => ($row['active'] ?? null) === true,
            ];
        }

        return $rows;
    }
}
