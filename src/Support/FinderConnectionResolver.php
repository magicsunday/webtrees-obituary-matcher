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
use function rtrim;

use const JSON_THROW_ON_ERROR;

/**
 * The single, webtrees-free source of truth for turning the three persisted finder-connection preferences
 * (`finder_transport`, `finder_base_url`, `finder_token`) into a validated {@see FinderConnection}, or
 * null when the connection is not configured. Both the admin control-panel handler and the headless CLI
 * adapters resolve their connection through this pure helper so the REST consent gate and the base-URL
 * validation can never drift between the UI and the CLI: the REST endpoint activates ONLY on the explicit
 * `finder_transport === 'rest'` consent marker (any other stored value — the legacy `'file'` or the unset
 * default — resolves to null even when a base URL lingers), an empty base URL is likewise "not
 * configured", and a stored-but-invalid base URL the {@see FinderConnection::rest()} source rejects is
 * treated as null rather than escaping as an exception. The token VALUE never leaves this method except
 * into the connection; the parameter is {@see SensitiveParameter} so a throw never spills it into a stack
 * trace.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class FinderConnectionResolver
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Resolves the persisted finder-connection preferences into a validated REST connection, or null when
     * it is not configured. Returns null unless the explicit consent marker `finder_transport === 'rest'`
     * is set AND a non-empty base URL is stored; otherwise the base URL and (when present) the token are
     * validated at the single {@see FinderConnection::rest()} source, a rejection being surfaced as null
     * rather than an exception. An empty token yields an unauthenticated connection (null token).
     *
     * @param string $transport The persisted `finder_transport` marker (only `'rest'` activates).
     * @param string $baseUrl   The persisted `finder_base_url` value.
     * @param string $token     The persisted `finder_token` value (an empty string means "no token").
     *
     * @return FinderConnection|null The validated REST connection, or null when not configured.
     */
    public static function fromConfig(
        string $transport,
        string $baseUrl,
        #[SensitiveParameter]
        string $token,
    ): ?FinderConnection {
        if ($transport !== 'rest') {
            return null;
        }

        if ($baseUrl === '') {
            return null;
        }

        try {
            return FinderConnection::rest($baseUrl, $token === '' ? null : $token);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Resolves the full list of ACTIVE finder connections the matcher composes over (§5.2f): the primary
     * connection ({@see self::fromConfig()}) followed by every active, valid ADDITIONAL finder decoded
     * from the `finder_additional` preference. The list is empty unless the module-wide REST consent
     * marker `finder_transport === 'rest'` is set — the same gate the single connection uses, so an
     * additional finder can never transmit person data without that consent. Each additional entry is
     * validated at the single {@see FinderConnection::rest()} source; an invalid or inactive entry is
     * skipped (never fatal), so one malformed row cannot suppress the others. An additional finder whose
     * base URL duplicates one already in the list (the primary or an earlier additional) is dropped, so
     * each connection has a distinct base URL — the invariant the per-finder ledger namespacing relies on.
     * A single-finder install yields exactly `[primary]`, identical to today.
     *
     * @param string $transport      The persisted `finder_transport` marker (only `'rest'` activates).
     * @param string $baseUrl        The persisted primary `finder_base_url` value.
     * @param string $token          The persisted primary `finder_token` value.
     * @param string $additionalJson The persisted `finder_additional` value (a JSON list of additional
     *                               finders), or an empty string when none are configured.
     *
     * @return list<FinderConnection> The active connections, primary first; empty when not configured.
     */
    public static function listFromConfig(
        string $transport,
        string $baseUrl,
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $additionalJson,
    ): array {
        if ($transport !== 'rest') {
            return [];
        }

        $connections  = [];
        $seenBaseUrls = [];

        $primary = self::fromConfig($transport, $baseUrl, $token);

        if ($primary instanceof FinderConnection) {
            $connections[]                                 = $primary;
            $seenBaseUrls[rtrim($primary->baseUrl(), '/')] = true;
        }

        foreach (self::decodeAdditional($additionalJson) as [$addBaseUrl, $addToken]) {
            // A duplicate base URL (the primary's or an earlier additional's) is skipped: each connection
            // must have a distinct base URL so the per-finder ledger namespacing keyed on it stays unique.
            // The key is compared with a trailing slash stripped so `https://f.example` and
            // `https://f.example/` — the same endpoint — dedup rather than double-searching that finder.
            if (isset($seenBaseUrls[rtrim($addBaseUrl, '/')])) {
                continue;
            }

            try {
                $connection = FinderConnection::rest($addBaseUrl, $addToken === '' ? null : $addToken);
            } catch (InvalidArgumentException) {
                // A malformed additional finder is skipped, not fatal: the primary and the other valid
                // additional finders still resolve (mirrors the null-on-rejection contract above).
                continue;
            }

            $connections[]                                    = $connection;
            $seenBaseUrls[rtrim($connection->baseUrl(), '/')] = true;
        }

        return $connections;
    }

    /**
     * Decodes the `finder_additional` preference into the ACTIVE additional finders as `[baseUrl, token]`
     * pairs. Defensive: invalid JSON, a non-list document, a non-object row, an inactive row (its `active`
     * flag is not exactly true), or a row without a string base URL is dropped rather than throwing, so a
     * corrupt preference can never crash the enqueue/drain path.
     *
     * @param string $additionalJson The persisted `finder_additional` JSON, or an empty string.
     *
     * @return list<array{0: string, 1: string}> The active additional finders as `[baseUrl, token]`.
     */
    private static function decodeAdditional(
        #[SensitiveParameter]
        string $additionalJson,
    ): array {
        if ($additionalJson === '') {
            return [];
        }

        try {
            $decoded = json_decode($additionalJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $active = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Strict identity to true: an inactive row, or a truthy-but-non-bool `active` (e.g. `1`), is
            // NOT treated as active — the flag must be a real boolean true.
            if (($row['active'] ?? null) !== true) {
                continue;
            }

            $rowBaseUrl = $row['baseUrl'] ?? null;

            if (!is_string($rowBaseUrl)) {
                continue;
            }

            if ($rowBaseUrl === '') {
                continue;
            }

            $rowToken = $row['token'] ?? null;

            $active[] = [$rowBaseUrl, is_string($rowToken) ? $rowToken : ''];
        }

        return $active;
    }
}
