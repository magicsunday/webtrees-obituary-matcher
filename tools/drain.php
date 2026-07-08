<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Headless CLI adapter that drains finished finder jobs into the per-tree match stores. It is a THIN
 * composition root: it boots the request-less webtrees runtime ({@see HeadlessBootstrap}), logs in the
 * system principal so every candidate is visible, wires the REST ingest object graph and hands the
 * single drain decision to {@see \MagicSunday\ObituaryMatcher\Webtrees\DrainService::drain()}. All domain logic lives in the injected
 * services; this file only parses options, assembles the graph, prints the one-line tally and maps the
 * outcome to an exit code.
 *
 * `--tree` is the NUMERIC webtrees tree id (the integer primary key); when omitted every tree is
 * drained. The finder connection (REST base URL and optional bearer token) is NOT passed on the command
 * line: it is read from the SAVED control-panel config of the registered module, under the same REST
 * consent gate the admin UI enforces, so the module must have REST configured and enabled
 * (`finder_transport === 'rest'` plus a valid stored base URL) or this adapter refuses to run. This keeps
 * the token strictly out of argv and logs — it lives only in the persisted preference and the outbound
 * Authorization header. `--rest-pending` is the in-flight ledger root directory (defaults to the running
 * instance's `data/obituary-matcher/rest-pending`, resolved relative to this module's install location).
 * `--limit` caps the number of done jobs processed this run (default 20). All are `=`-form long options.
 *
 * Usage:
 *   php tools/drain.php [--tree=1] [--rest-pending=/path/to/rest-pending] [--limit=20]
 *
 * Exit codes: 0 when no job failed, 1 when the boot fails, the module is not installed/enabled, the
 * finder connection is not configured, the ledger root cannot be located, or any job moved to
 * failed-ingest.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use MagicSunday\ObituaryMatcher\Webtrees\CliModuleResolver;
use MagicSunday\ObituaryMatcher\Webtrees\DrainFanOutResult;
use MagicSunday\ObituaryMatcher\Webtrees\DrainServiceFactory;
use MagicSunday\ObituaryMatcher\Webtrees\FinderCliConfigurationException;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;
use MagicSunday\ObituaryMatcher\Webtrees\RestCliBootstrap;

// This file lives in the global namespace, so `use function`/`use const` for built-ins is a no-op
// that emits a warning under newer PHP; the built-ins are referenced unqualified directly, matching
// the global-namespace entry-point convention used by module.php and seed-match-store.php.

// Resolve and register the Composer autoloader through the shared pre-autoload bootstrap, which tries
// the realistic install layouts (checkout dev tooling first) so this CLI boots in a non-checkout
// install too.
require __DIR__ . '/autoload.php';

// getopt() returns array|false; a false return (a parse failure) coerces to an empty option set so
// the array_key_exists() reads below stay type-honest rather than TypeError-ing on false.
$options = getopt('', [
    'tree::',
    'limit::',
    'rest-pending::',
]);
$options = $options === false ? [] : $options;

// The optional-value (`::`) long options parse as `false` when passed without the `=` form
// (`--tree 1`); reject that explicitly so a malformed flag gets a precise hint rather than being
// silently coerced to its default below.
foreach (['tree', 'limit', 'rest-pending'] as $flag) {
    if (
        array_key_exists($flag, $options)
        && !is_string($options[$flag])
    ) {
        fwrite(STDERR, sprintf('--%s requires the = form, e.g. --%s=value', $flag, $flag) . PHP_EOL);

        exit(1);
    }
}

$treeOption        = $options['tree'] ?? null;
$limitOption       = $options['limit'] ?? null;
$restPendingOption = $options['rest-pending'] ?? null;

// A non-numeric --tree is a typo (or a `../`-style segment); fail loud instead of coercing it to
// tree-0 via the (int) cast. An absent --tree means "every tree".
if (
    is_string($treeOption)
    && !ctype_digit($treeOption)
) {
    fwrite(STDERR, '--tree must be a numeric tree id (the integer primary key).' . PHP_EOL);

    exit(1);
}

$onlyTreeId = is_string($treeOption) ? (int) $treeOption : null;

// A non-positive or non-numeric --limit is a misuse; fall back to the default rather than draining
// zero (or a negative slice) jobs silently.
$limit = (is_string($limitOption) && ctype_digit($limitOption) && ((int) $limitOption > 0))
    ? (int) $limitOption
    : 20;

// Boot the request-less webtrees runtime and log in the system principal so the drain sees every
// candidate. A boot failure (missing config, no admin account, …) is unrecoverable: the shared
// HeadlessBootstrap::bootForCli() reports the fixed category WITHOUT leaking the DSN/credentials,
// routes the raw detail to the guarded error_log sink (the privacy-critical S46 handling) and exits
// non-zero. The PDOException-first arm ordering and the guarded sink live in that shared method.
HeadlessBootstrap::bootForCli('drain');

// Resolve the REGISTERED module and its REST wiring inside ONE guarded block. Both the module discovery
// and the persisted-config read touch the database, so a discovery/query fault is routed to the guarded
// sink below rather than escaping to cron STDERR. The connection is read from the saved control-panel
// config under the same REST consent gate the admin UI enforces; a not-configured/disabled connection
// fails fast with a fixed hint and the token never spills into argv or a stack trace.
try {
    // Module discovery needs the booted runtime, so it runs after the boot; a disabled or absent module
    // yields null and the CLI refuses (an inactive install must not drive the cron).
    $module = CliModuleResolver::resolveActiveModule();

    if ($module === null) {
        fwrite(STDERR, 'The obituary-matcher module is not installed or enabled in this webtrees instance.' . PHP_EOL);

        exit(1);
    }

    // §5.2f: resolve EVERY active finder (primary + additional) paired with its isolated ledger root, so
    // one drain run polls and ingests every configured finder's completed jobs. A single-finder install
    // yields exactly one pair, identical to before.
    $finders = RestCliBootstrap::resolveAll(
        $module,
        $restPendingOption,
        dirname(__DIR__),
    );
} catch (FinderCliConfigurationException $exception) {
    // A not-configured/disabled connection or an invalid --rest-pending path: the message is a fixed,
    // secret-free operator hint (never the stored base URL or token), so it is safe to echo.
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(1);
} catch (Throwable $exception) {
    // Any OTHER failure while resolving the connection — notably a database error from reading the
    // persisted preferences, whose message could embed SQL/DSN — must NOT reach cron output. Print a
    // fixed category and route the detail to the SAME guarded sink the bootstrap uses (error_log
    // defaults to STDERR in CLI, so it only logs when a real sink is configured — the S46 lesson).
    fwrite(STDERR, 'Could not resolve the finder configuration.' . PHP_EOL);
    HeadlessBootstrap::logCliError('drain', $exception);

    exit(1);
}

// Drain every finder's ledger via its own composition root (the per-job store is wired inside
// DrainService), aggregating the per-finder tallies. The admin-editable scoring weights are read from the
// module once so the drain scores with the operator's configured caps, not just the enriched defaults. A
// single-finder install runs the loop exactly once. Cross-finder de-duplication is handled downstream by
// the per-notice atomic store (last-writer-wins on the canonical URL key), not here.
$scoreConfig = $module->scoreConfig();

$summaries = [];

try {
    foreach ($finders as [$connection, $restPendingRoot]) {
        $summaries[] = DrainServiceFactory::create($connection, $restPendingRoot, $scoreConfig)
            ->drain($onlyTreeId, $limit);
    }
} catch (Throwable $exception) {
    // A DB/I-O fault DURING draining (TreeService::find / CandidateRepository::findByXrefs run webtrees
    // queries OUTSIDE DrainService's inner try) would otherwise escape to PHP's default uncaught-exception
    // handler and print the full message + stack trace (SQL text, connection name, absolute paths) to
    // STDERR/cron mail. Catch every Throwable, print a fixed category, and route the detail to the SAME
    // guarded sink the bootstrap uses (error_log defaults to STDERR in CLI, so it only logs when a real
    // sink is configured — the S46 lesson). This mirrors the enqueue adapter's producer guard.
    fwrite(STDERR, 'Drain failed.' . PHP_EOL);
    HeadlessBootstrap::logCliError('drain', $exception);

    exit(1);
}

$result = DrainFanOutResult::fromSummaries($summaries);

fwrite(
    STDOUT,
    sprintf(
        'ingested=%d skipped=%d failed=%d stored=%d stale=%d',
        $result->ingested,
        $result->skipped,
        $result->failed,
        $result->stored,
        $result->stale,
    ) . PHP_EOL,
);

exit($result->hasFailure() ? 1 : 0);
