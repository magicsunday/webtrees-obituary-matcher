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
use SensitiveParameter;

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
}
