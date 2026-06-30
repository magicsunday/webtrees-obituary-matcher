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
 * drained. `--base-url` is REQUIRED: the REST base URL of the obituary finder, and `--token` is the
 * optional bearer token. `--rest-pending` is the in-flight ledger root directory (defaults to the
 * running instance's `data/obituary-matcher/rest-pending`, resolved relative to this module's install
 * location). `--limit` caps the number of done jobs processed this run (default 20). All are `=`-form
 * long options. The `--base-url`/`--token` MUST match the control-panel finder config (reading
 * persisted module preferences from the CLI is deferred — it needs CLI module-instance resolution).
 *
 * Usage:
 *   php tools/drain.php --base-url=https://finder.example [--token=secret]
 *       [--tree=1] [--rest-pending=/path/to/rest-pending] [--limit=20]
 *
 * Exit codes: 0 when no job failed, 1 when the boot fails, `--base-url` is missing/invalid, the ledger
 * root cannot be located, or any job moved to failed-ingest.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\DrainServiceFactory;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;

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
    'base-url::',
    'token::',
    'rest-pending::',
]);
$options = $options === false ? [] : $options;

// The optional-value (`::`) long options parse as `false` when passed without the `=` form
// (`--tree 1`); reject that explicitly so a malformed flag gets a precise hint rather than being
// silently coerced to its default below.
foreach (['tree', 'limit', 'base-url', 'token', 'rest-pending'] as $flag) {
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
$baseUrlOption     = $options['base-url'] ?? null;
$tokenOption       = $options['token'] ?? null;
$restPendingOption = $options['rest-pending'] ?? null;

// --base-url is REQUIRED: the drain polls the REST finder for each in-flight job. A missing/empty base
// URL is a misuse; fail loud.
if (
    !is_string($baseUrlOption)
    || ($baseUrlOption === '')
) {
    fwrite(STDERR, '--base-url=<url> is required (the REST finder endpoint).' . PHP_EOL);

    exit(1);
}

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

// Resolve the REST in-flight ledger root: the explicit --rest-pending, else the running instance's
// default rest-pending dir resolved through the layout-independent locator (relative to this module's
// root, which is the tools/ parent). A default-resolved root that is merely absent is NOT an error —
// that is the first-run / nothing-enqueued-yet no-op the drain handles by discovering an empty ledger
// and exiting 0.
$restPendingRoot = is_string($restPendingOption)
    ? $restPendingOption
    : (new WebtreesInstallLocator(dirname(__DIR__)))->defaultRestPendingRoot();

if (!is_string($restPendingRoot)) {
    fwrite(STDERR, 'Could not locate the running-instance rest-pending dir beside this module; pass --rest-pending=<dir> explicitly.' . PHP_EOL);

    exit(1);
}

// Build the REST finder connection. A malformed --base-url (not http(s), or carrying a control
// character) is rejected by FinderConnection::rest(); catch it and fail loud rather than letting the
// stack trace (with the token in a header build's frame) reach STDERR.
$token = (is_string($tokenOption) && ($tokenOption !== '')) ? $tokenOption : null;

try {
    $connection = FinderConnection::rest($baseUrlOption, $token);
} catch (InvalidArgumentException) {
    fwrite(STDERR, 'Invalid REST connection: --base-url must be an http(s) URL and neither --base-url nor --token may contain control characters.' . PHP_EOL);

    exit(1);
}

// Assemble the drain graph via its composition root (the per-job store is wired inside DrainService).
$drainService = DrainServiceFactory::create($connection, $restPendingRoot);

$summary = $drainService->drain($onlyTreeId, $limit);

fwrite(
    STDOUT,
    sprintf(
        'ingested=%d skipped=%d failed=%d stored=%d stale=%d',
        $summary->ingested,
        $summary->skipped,
        $summary->failed,
        $summary->stored,
        $summary->stale,
    ) . PHP_EOL,
);

exit($summary->failed > 0 ? 1 : 0);
