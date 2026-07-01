<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;

use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * The shared REST bootstrap for the headless CLI adapters. After the config-only cutover the
 * `tools/enqueue.php` and `tools/drain.php` composition roots resolve the very same two things — the
 * validated {@see FinderConnection} (read from the PERSISTED module config, under the SAME REST consent
 * gate the admin control panel enforces) and the in-flight ledger root (explicit `--rest-pending` or the
 * running instance's default) — so that wiring lives here once and the two adapters cannot drift. The
 * connection is NO LONGER taken from `--base-url`/`--token` arguments: it is resolved from the module's
 * saved preferences through {@see FinderConnectionResolver}, so the token only ever lives in the persisted
 * preference and the outbound Authorization header, never in argv or a log line. Every misuse throws a
 * {@see FinderCliConfigurationException} whose message is the exact operator-facing hint; the caller
 * prints it to STDERR and exits non-zero, while routing any OTHER {@see \Throwable} (e.g. a database error
 * from reading the preferences) to the guarded log sink so no internal detail reaches cron output.
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
     * Resolves the validated REST finder connection and the in-flight ledger root shared by the enqueue
     * and drain adapters. The connection is read from the PERSISTED module config through
     * {@see FinderConnectionResolver::fromConfig()}, under the SAME REST consent gate the admin control
     * panel enforces: unless `finder_transport === 'rest'` is set and a valid base URL is stored the
     * connection is null and a misuse is raised. The ledger root is the explicit `--rest-pending` value
     * or, when absent, the running instance's default resolved through the layout-independent locator
     * relative to the module root; a default-resolved root that is merely absent is NOT an error (the
     * ledger creates it on first record). The operator hint never echoes the stored base URL or token.
     *
     * @param ObituaryMatcherModule $module            The registered module whose saved preferences carry the finder connection.
     * @param string|null           $restPendingOption The raw `--rest-pending` value (already narrowed to string|null).
     * @param string                $moduleRoot        The module root directory (the `tools/` parent) the ledger default is resolved from.
     *
     * @return array{FinderConnection, string} The validated connection and the resolved ledger root.
     *
     * @throws FinderCliConfigurationException When the finder connection is not configured (REST not enabled or no valid
     *                                         stored base URL), or the ledger root cannot be located.
     */
    public static function resolve(
        ObituaryMatcherModule $module,
        ?string $restPendingOption,
        string $moduleRoot,
    ): array {
        // The connection comes from the persisted control-panel config, NOT from argv: this enforces the
        // same consent gate the admin UI uses (finder_transport === 'rest' plus a valid base URL) and
        // keeps the token out of the process arguments. A not-configured/disabled connection is a misuse;
        // the hint points the operator at the control panel and never echoes the stored credentials.
        $connection = FinderConnectionResolver::fromConfig(
            $module->getPreference('finder_transport', ''),
            $module->getPreference('finder_base_url', ''),
            $module->getPreference('finder_token', ''),
        );

        if (!$connection instanceof FinderConnection) {
            throw new FinderCliConfigurationException(
                'The finder connection is not configured or REST is not enabled — open the module control panel, enter the REST base URL and token, and save.',
            );
        }

        $restPendingRoot = self::ledgerRoot($restPendingOption, $moduleRoot);

        return [$connection, $restPendingRoot];
    }

    /**
     * Resolves the in-flight ledger root: an explicit `--rest-pending` value (which MUST be an absolute
     * path to a dedicated directory) or, when absent, the running instance's default resolved through the
     * layout-independent locator relative to the module root. An explicit empty, relative, or filesystem-
     * root value is rejected: enqueue and drain routinely run from different working directories (cron,
     * manual), so a relative value would resolve to DIFFERENT ledgers and strand an accepted remote job,
     * while a root-level ledger would scan/write/remove `*.json` entries at `/`. A trailing separator is
     * normalised away. The default-resolved root is always absolute (it is realpath-derived), so the
     * absolute requirement never rejects it.
     *
     * @param string|null $restPendingOption The raw `--rest-pending` value (already narrowed to string|null).
     * @param string      $moduleRoot        The module root directory the default is resolved from.
     *
     * @return string The resolved absolute ledger root (any trailing separator removed).
     *
     * @throws FinderCliConfigurationException On an unlocatable default root or an empty/relative/filesystem-root explicit path.
     */
    private static function ledgerRoot(?string $restPendingOption, string $moduleRoot): string
    {
        // No explicit --rest-pending: resolve the running instance's default beside this module. A
        // merely-absent default (nothing enqueued yet) is not an error for the ledger, but an
        // UNLOCATABLE install is a misuse the caller must resolve with an explicit path.
        if ($restPendingOption === null) {
            $default = (new WebtreesInstallLocator($moduleRoot))->defaultRestPendingRoot();

            if (!is_string($default)) {
                throw new FinderCliConfigurationException(
                    'Could not locate the running-instance rest-pending dir beside this module; pass --rest-pending=<dir> explicitly.',
                );
            }

            return $default;
        }

        // An explicit --rest-pending MUST be an absolute path to a DEDICATED directory. Normalise a
        // trailing separator first, so the empty string and the filesystem root ('/' or '//') both
        // collapse to '' and are rejected together with a relative value: a relative root would resolve
        // differently across enqueue's and drain's working directories, and a root-level ledger would
        // scan/write/remove *.json entries at '/' — under a privileged cron/container touching unrelated
        // files. This lexical guard targets ACCIDENTAL misuse; root-equivalent dot-segment forms ('/.',
        // '/foo/..') are deliberately not rejected, because the root is an operator-supplied CLI argument
        // (a trusted boundary — the operator already holds the process's filesystem access), and the only
        // total defence, realpath(), would wrongly reject a valid nonexistent first-run root.
        $normalized = rtrim($restPendingOption, '/');

        if (
            ($normalized === '')
            || !str_starts_with($restPendingOption, '/')
        ) {
            throw new FinderCliConfigurationException(
                '--rest-pending must be an absolute path to a dedicated directory (e.g. /var/lib/webtrees/data/obituary-matcher/rest-pending).',
            );
        }

        return $normalized;
    }
}
