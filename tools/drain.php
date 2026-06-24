<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Headless CLI adapter that drains finished feeder jobs into the per-tree match stores. It is a THIN
 * composition root: it boots the request-less webtrees runtime ({@see HeadlessBootstrap}), logs in the
 * system principal so every candidate is visible, wires the queue/ingest object graph and hands the
 * single drain decision to {@see DrainService::drain()}. All domain logic lives in the injected
 * services; this file only parses options, assembles the graph, prints the one-line tally and maps the
 * outcome to an exit code.
 *
 * `--tree` is the NUMERIC webtrees tree id (the integer primary key); when omitted every tree is
 * drained. `--queue` is the queue root directory (defaults to the running instance's
 * `data/obituary-matcher/queue`, resolved relative to this module's install location). `--limit` caps
 * the number of done jobs processed this run (default 20). All three are `=`-form long options.
 *
 * Usage:
 *   php tools/drain.php [--tree=1] [--queue=/path/to/queue] [--limit=20]
 *
 * Exit codes: 0 when no job failed, 1 when the boot fails or any job moved to failed-ingest.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use MagicSunday\ObituaryMatcher\Matching\IngestService;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\DrainService;
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
    'queue::',
    'limit::',
]);
$options = $options === false ? [] : $options;

// The optional-value (`::`) long options parse as `false` when passed without the `=` form
// (`--tree 1`); reject that explicitly so a malformed flag gets a precise hint rather than being
// silently coerced to its default below.
foreach (['tree', 'queue', 'limit'] as $flag) {
    if (
        array_key_exists($flag, $options)
        && !is_string($options[$flag])
    ) {
        fwrite(STDERR, sprintf('--%s requires the = form, e.g. --%s=value', $flag, $flag) . PHP_EOL);

        exit(1);
    }
}

$treeOption  = $options['tree'] ?? null;
$queueOption = $options['queue'] ?? null;
$limitOption = $options['limit'] ?? null;

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

// Resolve the queue root: the explicit --queue, else the running instance's default queue dir resolved
// through the layout-independent locator (relative to this module's root, which is the tools/ parent).
$queueRoot = is_string($queueOption)
    ? $queueOption
    : (new WebtreesInstallLocator(dirname(__DIR__)))->defaultQueueRoot();

if (!is_string($queueRoot)) {
    fwrite(STDERR, 'Could not locate the running-instance queue dir beside this module; pass --queue=<dir> explicitly.' . PHP_EOL);

    exit(1);
}

// An EXPLICIT --queue that does not exist is an operator typo: fail loud rather than silently draining
// zero jobs. A DEFAULT-resolved root that is merely absent is NOT an error — that is the first-run /
// feeder-has-not-run-yet no-op the drain handles by discovering an empty done/ state and exiting 0.
if (is_string($queueOption) && !is_dir($queueOption)) {
    fwrite(STDERR, sprintf('The --queue directory does not exist: %s', $queueOption) . PHP_EOL);

    exit(1);
}

// Assemble the drain object graph. The store is NOT wired here: DrainService builds the tree-scoped
// store per job through its MatchStoreFactory seam, so the ingest stays store-agnostic.
$paths = new QueuePaths($queueRoot);

$drainService = new DrainService(
    $paths,
    new QueueClient($paths),
    new FeederRequestReader($paths, 5_242_880),
    new CandidateRepository(),
    new IngestService(
        new ResponseReader($paths),
        new EnrichedMatchEngine(),
        new Classifier(),
    ),
    new TreeService(new GedcomImportService()),
);

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
