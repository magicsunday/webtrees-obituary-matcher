<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Headless CLI adapter that enqueues ONE bounded finder job for a single tree's death-date-missing
 * candidates. It is a THIN composition root: it boots the request-less webtrees runtime
 * ({@see HeadlessBootstrap}), logs in the system principal so every candidate is visible, wires the
 * REST producer object graph and hands the single enqueue decision to {@see EnqueueService::enqueue()}.
 * All domain logic lives in the injected services; this file only parses options, assembles the graph,
 * prints the one-line tally and maps the outcome to an exit code.
 *
 * `--tree` is REQUIRED: it is the NUMERIC webtrees tree id (the integer primary key) of the single
 * tree to enqueue. `--base-url` is REQUIRED: the REST base URL of the obituary finder, and `--token` is
 * the optional bearer token. `--rest-pending` is the in-flight ledger root directory (defaults to the
 * running instance's `data/obituary-matcher/rest-pending`, resolved relative to this module's install
 * location). `--limit` caps the number of candidates written into the request (default 50). `--min-age`
 * is the minimum age (in years) a candidate must have reached for inclusion (default 90). All are
 * `=`-form long options. The `--base-url`/`--token` MUST match the control-panel finder config (reading
 * persisted module preferences from the CLI is deferred — it needs CLI module-instance resolution).
 *
 * Usage:
 *   php tools/enqueue.php --tree=1 --base-url=https://finder.example [--token=secret]
 *       [--rest-pending=/path/to/rest-pending] [--limit=50] [--min-age=90]
 *
 * Exit codes: 0 on a completed run (including a run that finds zero candidates), 1 when the boot
 * fails, the DB is unreachable, `--tree` is missing/non-numeric, `--base-url` is missing/invalid, the
 * ledger root cannot be located, the tree id is unknown, or the enqueue itself fails.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use Fisharebest\Webtrees\I18N;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueServiceFactory;
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
    'tree:',        // required → single colon
    'limit::',
    'min-age::',
    'base-url::',
    'token::',
    'rest-pending::',
]);
$options = $options === false ? [] : $options;

// The optional-value (`::`) long options parse as `false` when passed without the `=` form
// (`--base-url https://x`); reject that explicitly so a malformed flag gets a precise hint rather than
// being silently coerced to its default below.
foreach (['limit', 'min-age', 'base-url', 'token', 'rest-pending'] as $flag) {
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
$minAgeOption      = $options['min-age'] ?? null;
$baseUrlOption     = $options['base-url'] ?? null;
$tokenOption       = $options['token'] ?? null;
$restPendingOption = $options['rest-pending'] ?? null;

// Resolve the REST wiring (the required --base-url, the in-flight ledger root and the validated finder
// connection) through the shared bootstrap BEFORE the expensive boot, so a CLI/connection misuse fails
// fast with a fixed hint and the token never spills into a stack trace.
try {
    [$connection, $restPendingRoot] = RestCliBootstrap::resolve(
        $baseUrlOption,
        $tokenOption,
        $restPendingOption,
        dirname(__DIR__),
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(1);
}

// --tree is REQUIRED and must be numeric: the producer enqueues exactly one tree per run. A missing,
// non-string (the required-value `:` form passed without `=`), or non-numeric --tree is a misuse;
// fail loud rather than coercing it to tree-0 via the (int) cast.
if (
    !is_string($treeOption)
    || !ctype_digit($treeOption)
) {
    fwrite(STDERR, '--tree=<numeric id> is required (the single tree to enqueue).' . PHP_EOL);

    exit(1);
}

$treeId = (int) $treeOption;

// A non-positive or non-numeric --limit / --min-age is a misuse; fall back to the default rather than
// enqueueing zero (or a negative slice of) candidates silently.
$limit = (is_string($limitOption) && ctype_digit($limitOption) && ((int) $limitOption > 0))
    ? (int) $limitOption
    : 50;

$minAge = (is_string($minAgeOption) && ctype_digit($minAgeOption) && ((int) $minAgeOption > 0))
    ? (int) $minAgeOption
    : 90;

// Boot the request-less webtrees runtime and log in the system principal so the producer sees every
// candidate. A boot failure (missing config, no admin account, …) is unrecoverable: the shared
// HeadlessBootstrap::bootForCli() reports the fixed category WITHOUT leaking the DSN/credentials,
// routes the raw detail to the guarded error_log sink (the privacy-critical S46 handling) and exits
// non-zero. The PDOException-first arm ordering and the guarded sink live in that shared method.
HeadlessBootstrap::bootForCli('enqueue');

// Resolve the locale from the booted instance language so the finder queries carry the instance's
// configured language tag.
$locale = I18N::languageTag();

// Assemble the producer object graph over the connection and ledger root resolved above. The reference
// year defaults to null (the current year) — it is a service-only seam with no CLI flag.
$enqueueService = EnqueueServiceFactory::create($connection, $restPendingRoot);

try {
    $summary = $enqueueService->enqueue($treeId, $limit, $minAge, $locale);
} catch (DomainException) {
    // An unknown/vanished tree id: a fixed error + non-zero exit (the tree binding is required).
    fwrite(STDERR, sprintf('Unknown tree id: %d', $treeId) . PHP_EOL);

    exit(1);
} catch (Throwable $exception) {
    // Any other producer failure — a transport RuntimeException, but ALSO any TypeError/Error/
    // InvalidArgumentException (the last extends LogicException, not RuntimeException) that could
    // otherwise escape to PHP's default uncaught-exception handler and print the full message + stack
    // trace (with absolute paths) to STDERR/cron mail. Catch every Throwable, print a fixed category,
    // and route the detail to the SAME guarded sink the bootstrap uses (error_log defaults to STDERR
    // in CLI, so only log when a real sink is configured — the S46 lesson). DomainException (the
    // operator's own numeric --tree) is handled above, safe to echo.
    fwrite(STDERR, 'Enqueue failed.' . PHP_EOL);
    HeadlessBootstrap::logCliError('enqueue', $exception);

    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        'enqueued=%s candidates=%d skipped_inflight=%d excluded_hosts=%d tree=%d',
        $summary->jobId ?? 'none',
        $summary->candidates,
        $summary->skippedInflight,
        $summary->excludedHosts,
        $treeId,
    ) . PHP_EOL,
);

exit(0);
