<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use RuntimeException;
use SensitiveParameter;

use function is_string;
use function str_starts_with;

/**
 * The shared REST bootstrap for the headless CLI adapters. After the REST cutover the `tools/enqueue.php`
 * and `tools/drain.php` composition roots resolve the very same three things from their parsed options —
 * the required `--base-url`, the in-flight ledger root (explicit `--rest-pending` or the running
 * instance's default) and the validated {@see FinderConnection} — so that wiring lives here once and the
 * two adapters cannot drift. Every misuse throws a {@see RuntimeException} whose message is the exact
 * operator-facing hint; the caller prints it to STDERR and exits non-zero. The token is a
 * {@see SensitiveParameter} so a throw from anywhere in this method never spills it into a stack trace.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RestCliBootstrap
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Resolves the validated REST finder connection and the in-flight ledger root from the parsed CLI
     * options shared by the enqueue and drain adapters. The ledger root is the explicit `--rest-pending`
     * value or, when absent, the running instance's default resolved through the layout-independent
     * locator relative to the module root; a default-resolved root that is merely absent is NOT an error
     * (the ledger creates it on first record). A malformed base URL or token is rejected at the single
     * {@see FinderConnection::rest()} source, and the failure message never echoes the secret.
     *
     * @param string|null $baseUrlOption     The raw `--base-url` value (already narrowed to string|null).
     * @param string|null $tokenOption       The raw `--token` value (already narrowed to string|null).
     * @param string|null $restPendingOption The raw `--rest-pending` value (already narrowed to string|null).
     * @param string      $moduleRoot        The module root directory (the `tools/` parent) the ledger default is resolved from.
     *
     * @return array{FinderConnection, string} The validated connection and the resolved ledger root.
     *
     * @throws RuntimeException On a missing/empty base URL, an unlocatable ledger root, or an invalid REST connection.
     */
    public static function resolve(
        ?string $baseUrlOption,
        #[SensitiveParameter]
        ?string $tokenOption,
        ?string $restPendingOption,
        string $moduleRoot,
    ): array {
        // --base-url is REQUIRED: every REST adapter talks to the finder endpoint. A missing/empty base
        // URL is a misuse; fail loud.
        if (
            ($baseUrlOption === null)
            || ($baseUrlOption === '')
        ) {
            throw new RuntimeException('--base-url=<url> is required (the REST finder endpoint).');
        }

        $restPendingRoot = self::ledgerRoot($restPendingOption, $moduleRoot);

        $token = (($tokenOption !== null) && ($tokenOption !== '')) ? $tokenOption : null;

        // A malformed base URL (not http(s), or carrying a control character) is rejected by
        // FinderConnection::rest(); rethrow a fixed hint rather than letting the original stack trace
        // (with the token in a header build's frame) escape to STDERR.
        try {
            $connection = FinderConnection::rest($baseUrlOption, $token);
        } catch (InvalidArgumentException) {
            throw new RuntimeException(
                'Invalid REST connection: --base-url must be an http(s) URL and neither --base-url nor --token may contain control characters.',
            );
        }

        return [$connection, $restPendingRoot];
    }

    /**
     * Resolves the in-flight ledger root: an explicit `--rest-pending` value (which MUST be a non-empty
     * absolute path) or, when absent, the running instance's default resolved through the layout-
     * independent locator relative to the module root. An explicit relative path is rejected because
     * enqueue and drain routinely run from different working directories (cron, manual), so the same
     * relative value would resolve to DIFFERENT ledgers and strand an accepted remote job — enqueue
     * records it under one root while drain scans another and exits clean. The default-resolved root is
     * always absolute (it is realpath-derived), so the absolute requirement never rejects it.
     *
     * @param string|null $restPendingOption The raw `--rest-pending` value (already narrowed to string|null).
     * @param string      $moduleRoot        The module root directory the default is resolved from.
     *
     * @return string The resolved absolute ledger root.
     *
     * @throws RuntimeException On an unlocatable default root or an empty/relative explicit path.
     */
    private static function ledgerRoot(?string $restPendingOption, string $moduleRoot): string
    {
        // No explicit --rest-pending: resolve the running instance's default beside this module. A
        // merely-absent default (nothing enqueued yet) is not an error for the ledger, but an
        // UNLOCATABLE install is a misuse the caller must resolve with an explicit path.
        if ($restPendingOption === null) {
            $default = (new WebtreesInstallLocator($moduleRoot))->defaultRestPendingRoot();

            if (!is_string($default)) {
                throw new RuntimeException(
                    'Could not locate the running-instance rest-pending dir beside this module; pass --rest-pending=<dir> explicitly.',
                );
            }

            return $default;
        }

        if (
            ($restPendingOption === '')
            || !str_starts_with($restPendingOption, '/')
        ) {
            throw new RuntimeException(
                '--rest-pending must be a non-empty absolute path (e.g. /var/lib/webtrees/data/obituary-matcher/rest-pending).',
            );
        }

        return $restPendingOption;
    }
}
