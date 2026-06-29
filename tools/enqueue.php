<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Headless CLI adapter that enqueues ONE bounded feeder job for a single tree's death-date-missing
 * candidates. It is a THIN composition root: it boots the request-less webtrees runtime
 * ({@see HeadlessBootstrap}), logs in the system principal so every candidate is visible, wires the
 * queue/producer object graph and hands the single enqueue decision to {@see EnqueueService::enqueue()}.
 * All domain logic lives in the injected services; this file only parses options, assembles the graph,
 * prints the one-line tally and maps the outcome to an exit code.
 *
 * `--tree` is REQUIRED: it is the NUMERIC webtrees tree id (the integer primary key) of the single
 * tree to enqueue. `--queue` is the queue root directory (defaults to the running instance's
 * `data/obituary-matcher/queue`, resolved relative to this module's install location). `--limit` caps
 * the number of candidates written into the request (default 50). `--min-age` is the minimum age (in
 * years) a candidate must have reached for inclusion (default 90). `--transport` selects the finder
 * transport (`file`, the default, or `rest`); for `rest`, `--base-url` is REQUIRED and `--token` is the
 * optional bearer token, and the REST in-flight ledger is placed beside the queue dir. All are `=`-form
 * long options.
 *
 * Usage:
 *   php tools/enqueue.php --tree=1 [--queue=/path/to/queue] [--limit=50] [--min-age=90]
 *   php tools/enqueue.php --tree=1 --transport=rest --base-url=https://finder.example [--token=secret]
 *
 * Exit codes: 0 on a completed run (including a run that finds zero candidates), 1 when the boot
 * fails, the DB is unreachable, `--tree` is missing/non-numeric, the tree id is unknown, or the
 * enqueue itself fails.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use Fisharebest\Webtrees\I18N;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueServiceFactory;
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
    'tree:',        // required → single colon
    'queue::',
    'limit::',
    'min-age::',
    'transport::',
    'base-url::',
    'token::',
]);
$options = $options === false ? [] : $options;

// The optional-value (`::`) long options parse as `false` when passed without the `=` form
// (`--queue /path`); reject that explicitly so a malformed flag gets a precise hint rather than being
// silently coerced to its default below.
foreach (['queue', 'limit', 'min-age', 'transport', 'base-url', 'token'] as $flag) {
    if (
        array_key_exists($flag, $options)
        && !is_string($options[$flag])
    ) {
        fwrite(STDERR, sprintf('--%s requires the = form, e.g. --%s=value', $flag, $flag) . PHP_EOL);

        exit(1);
    }
}

$treeOption      = $options['tree'] ?? null;
$queueOption     = $options['queue'] ?? null;
$limitOption     = $options['limit'] ?? null;
$minAgeOption    = $options['min-age'] ?? null;
$transportOption = $options['transport'] ?? null;
$baseUrlOption   = $options['base-url'] ?? null;
$tokenOption     = $options['token'] ?? null;

// --transport selects the finder transport: the default file-drop queue, or the REST endpoint. Any
// other value is a misuse; fail loud rather than silently falling back to file.
if (
    is_string($transportOption)
    && !in_array($transportOption, ['file', 'rest'], true)
) {
    fwrite(STDERR, '--transport must be "file" or "rest".' . PHP_EOL);

    exit(1);
}

$transport = $transportOption === 'rest' ? 'rest' : 'file';

// --base-url is REQUIRED for the REST transport (the file transport ignores it). A missing/empty base
// URL is a misuse; fail loud.
if (
    $transport === 'rest'
    && (!is_string($baseUrlOption) || ($baseUrlOption === ''))
) {
    fwrite(STDERR, '--base-url=<url> is required when --transport=rest.' . PHP_EOL);

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

// Resolve the queue root: the explicit --queue, else the running instance's default queue dir resolved
// through the layout-independent locator (relative to this module's root, which is the tools/ parent).
$queueRoot = is_string($queueOption)
    ? $queueOption
    : (new WebtreesInstallLocator(dirname(__DIR__)))->defaultQueueRoot();

if (!is_string($queueRoot)) {
    fwrite(STDERR, 'Could not locate the running-instance queue dir beside this module; pass --queue=<dir> explicitly.' . PHP_EOL);

    exit(1);
}

// An EXPLICIT --queue that does not exist is an operator typo: fail loud rather than silently writing
// into a missing tree. A DEFAULT-resolved root that is merely absent is NOT an error — the QueueClient
// creates the queue subdirectories on first enqueue.
if (is_string($queueOption) && !is_dir($queueOption)) {
    fwrite(STDERR, sprintf('The --queue directory does not exist: %s', $queueOption) . PHP_EOL);

    exit(1);
}

// Resolve the locale from the booted instance language so the feeder queries carry the instance's
// configured language tag.
$locale = I18N::languageTag();

// Build the finder connection and, for the REST transport, the in-flight ledger root as a sibling of
// the queue dir. A malformed --base-url (not http(s), or carrying a control character) is rejected by
// FinderConnection::rest(); catch it and fail loud rather than letting the stack trace (with the token
// in a header build's frame) reach STDERR.
$token = (is_string($tokenOption) && ($tokenOption !== '')) ? $tokenOption : null;

try {
    $connection = $transport === 'rest'
        ? FinderConnection::rest($baseUrlOption, $token)
        : FinderConnection::file();
} catch (InvalidArgumentException) {
    fwrite(STDERR, 'Invalid REST connection: --base-url must be an http(s) URL and neither --base-url nor --token may contain control characters.' . PHP_EOL);

    exit(1);
}

$restPendingRoot = $transport === 'rest'
    ? dirname(rtrim($queueRoot, '/')) . '/rest-pending'
    : null;

// Assemble the producer object graph. The reference year defaults to null (the current year) — it is a
// service-only seam with no CLI flag.
$paths = new QueuePaths($queueRoot);

$enqueueService = EnqueueServiceFactory::create($paths, $connection, $restPendingRoot);

try {
    $summary = $enqueueService->enqueue($treeId, $limit, $minAge, $locale);
} catch (DomainException) {
    // An unknown/vanished tree id: a fixed error + non-zero exit (the tree binding is required).
    fwrite(STDERR, sprintf('Unknown tree id: %d', $treeId) . PHP_EOL);

    exit(1);
} catch (Throwable $exception) {
    // Any other producer failure — a QueueClient::enqueue clobber/filesystem RuntimeException, but
    // ALSO any TypeError/Error/InvalidArgumentException (the last extends LogicException, not
    // RuntimeException) that could otherwise escape to PHP's default uncaught-exception handler and
    // print the full message + stack trace (with absolute paths) to STDERR/cron mail. Catch every
    // Throwable, print a fixed category, and route the detail to the SAME guarded sink the bootstrap
    // uses (error_log defaults to STDERR in CLI, so only log when a real sink is configured — the
    // S46 lesson). DomainException (the operator's own numeric --tree) is handled above, safe to echo.
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
