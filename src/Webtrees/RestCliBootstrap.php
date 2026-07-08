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

use function hash;
use function is_string;
use function rtrim;
use function str_starts_with;
use function substr;

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
     * Resolves EVERY active finder connection (§5.2f) paired with its own isolated in-flight ledger root,
     * for the multi-finder fan-out: the primary connection plus every active additional finder, read from
     * the persisted config through {@see FinderConnectionResolver::listFromConfig()} under the same REST
     * consent gate. A single-finder install yields exactly one pair `[primary, base ledger root]` — the
     * unchanged single-finder behaviour. Each additional finder gets its OWN ledger sub-root (namespaced
     * by a hash of its base URL, so it stays stable across reordering) so the finders' in-flight sets never
     * mix; the primary keeps the unchanged base ledger root, so no existing in-flight job is orphaned.
     *
     * @param ObituaryMatcherModule $module            The module the persisted config is read from.
     * @param string|null           $restPendingOption The raw `--rest-pending` value (already narrowed).
     * @param string                $moduleRoot        The module root the ledger default is resolved from.
     *
     * @return list<array{FinderConnection, string}> The per-finder connection + isolated ledger-root pairs.
     *
     * @throws FinderCliConfigurationException When no finder is configured (REST not enabled or no valid
     *                                         connection), or the ledger root cannot be located.
     */
    public static function resolveAll(
        ObituaryMatcherModule $module,
        ?string $restPendingOption,
        string $moduleRoot,
    ): array {
        $transport = $module->getPreference('finder_transport', '');

        $connections = FinderConnectionResolver::listFromConfig(
            $transport,
            $module->getPreference('finder_base_url', ''),
            $module->getPreference('finder_token', ''),
            $module->getPreference('finder_additional', ''),
        );

        if ($connections === []) {
            throw new FinderCliConfigurationException(
                'The finder connection is not configured or REST is not enabled — open the module control panel, enter the REST base URL and token, and save.',
            );
        }

        $baseRoot = self::ledgerRoot($restPendingOption, $moduleRoot);

        // Key the ledger root on finder IDENTITY, not list position: the PRIMARY connection keeps the
        // unchanged base ledger root (so its existing in-flight jobs are never orphaned), and EVERY other
        // (additional) finder gets an isolated sub-ledger keyed by a hash of its base URL. Resolving the
        // primary base URL here (rather than assuming index 0 is the primary) keeps the mapping stable
        // even when the primary is unset — then no finder claims the base root and every additional finder
        // uses its own hash sub-root, so configuring a primary later cannot strand an additional's jobs.
        $primaryBaseUrl = FinderConnectionResolver::fromConfig(
            $transport,
            $module->getPreference('finder_base_url', ''),
            $module->getPreference('finder_token', ''),
        )?->baseUrl();

        $pairs = [];

        foreach ($connections as $connection) {
            $ledgerRoot = $connection->baseUrl() === $primaryBaseUrl
                ? $baseRoot
                : $baseRoot . '/finder-' . substr(hash('sha256', $connection->baseUrl()), 0, 16);

            $pairs[] = [$connection, $ledgerRoot];
        }

        return $pairs;
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
