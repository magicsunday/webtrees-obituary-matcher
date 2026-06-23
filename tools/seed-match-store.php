<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Developer-only CLI: seed a tree-scoped match store with one synthetic suggestion so the individual
 * tab can be inspected on a real instance without running the producer, feeder or scoring engine. The
 * written payload is fabricated (reserved `.example` domain, no real obituary data), so it is safe to
 * run against a development tree (DSGVO).
 *
 * `--tree-id` is the NUMERIC webtrees tree id (the integer primary key, NOT the tree name or an
 * XREF); it selects the per-tree store directory `tree-<id>` the same way the module does at runtime.
 *
 * `--data-path` defaults to the running instance's webtrees data dir resolved RELATIVE to this
 * module's install location (the sibling `vendor/fisharebest/webtrees/data`); pass it explicitly
 * for a standalone checkout that has no sibling webtrees. (Note: getopt long options declared with
 * `::` such as `--data-path` require the `=` form — `--data-path=/dir`, not `--data-path /dir`.)
 *
 * Usage:
 *   php tools/seed-match-store.php --tree-id=1 --person=I1 \
 *       [--status=pending] [--band=strong] [--death=2023-09-04] [--data-path=/path/to/data]
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;

// This file lives in the global namespace, so `use function`/`use const` for built-ins is a no-op
// that emits a warning under newer PHP; the built-ins are referenced unqualified directly, matching
// the global-namespace entry-point convention used by module.php.

// Resolve and register the Composer autoloader through the shared pre-autoload bootstrap, which tries
// the realistic install layouts (checkout dev tooling first) so this CLI boots in a non-checkout
// install too.
require __DIR__ . '/autoload.php';

$options = getopt('', [
    'tree-id:',
    'person:',
    'status::',
    'band::',
    'death::',
    'data-path::',
]);

// getopt() returns false on a parse failure; normalise to an array so the array_key_exists() probes
// below cannot TypeError (matches tools/drain.php).
$options = $options === false ? [] : $options;

$treeId = $options['tree-id'] ?? null;
$person = $options['person'] ?? null;

if (
    !is_string($treeId)
    || !is_string($person)
) {
    fwrite(STDERR, 'Both --tree-id (numeric tree id) and --person (XREF) are required.' . PHP_EOL);

    exit(1);
}

// Fail loud on a non-numeric --tree-id (a typo, or a `../`-style segment) instead of silently
// coercing it to tree-0 via the (int) cast below.
if (!ctype_digit($treeId)) {
    fwrite(STDERR, '--tree-id must be a numeric tree id (the integer primary key).' . PHP_EOL);

    exit(1);
}

$statusValue = $options['status'] ?? 'pending';
$status      = is_string($statusValue) ? MatchStatus::tryFrom($statusValue) : null;

if (!$status instanceof MatchStatus) {
    fwrite(STDERR, sprintf('Unknown --status value: %s', is_string($statusValue) ? $statusValue : '') . PHP_EOL);

    exit(1);
}

$bandValue = $options['band'] ?? 'strong';
$band      = is_string($bandValue) ? $bandValue : 'strong';

$death = (array_key_exists('death', $options) && is_string($options['death'])) ? $options['death'] : null;

// Resolve the RUNNING instance's webtrees data dir, which sits beside this module in the shared
// vendor/ (this module installs at vendor/magicsunday/webtrees-obituary-matcher, so webtrees is
// vendor/fisharebest/webtrees). `Webtrees::DATA_DIR` is deliberately NOT used here: in this
// standalone CLI it loads the .build TEST webtrees, whose data dir is not the live instance. The
// sibling module-updater can use `Webtrees::DATA_DIR` only because it runs as module code inside
// the live instance; this CLI cannot. A checkout without that sibling layout must pass --data-path.
// `--data-path` is an optional-value (`::`) long option, so passing it without the `=` form
// (`--data-path /dir`) parses as `false` rather than the directory. Detect that malformed case
// explicitly so the user who DID pass the flag gets a precise hint instead of the generic
// "could not locate the sibling" error below.
if (
    array_key_exists('data-path', $options)
    && !is_string($options['data-path'])
) {
    fwrite(STDERR, '--data-path requires the = form, e.g. --data-path=/path/to/data' . PHP_EOL);

    exit(1);
}

// Without --data-path, resolve the running instance's match store under the live webtrees data dir
// through the layout-independent locator (relative to this module's root, the tools/ parent). It
// handles both the Composer/VendorModuleService install (webtrees as the sibling vendor/fisharebest/
// webtrees) and the classic zip/modules_v4 drop-in (data dir two levels up); a checkout without either
// layout must pass --data-path. The locator returns the data dir (<install>/data); the match store
// lives at <data>/obituary-matcher/matches.
$explicitDataPath = $options['data-path'] ?? null;
$dataDir          = (new WebtreesInstallLocator(dirname(__DIR__)))->dataDir();
$baseValue        = is_string($explicitDataPath)
    ? $explicitDataPath
    : (($dataDir !== null) ? $dataDir . '/obituary-matcher/matches' : null);

if (!is_string($baseValue)) {
    fwrite(STDERR, 'Could not locate the running-instance webtrees data dir beside this module; pass --data-path=<dir> explicitly.' . PHP_EOL);

    exit(1);
}

// Reuse the single tree-path definition; the CLI has only the numeric id (no Tree object). Casting
// to int also strips any non-numeric segment such as `../`, closing a latent path-traversal shape.
$dir = MatchStoreFactory::pathForTreeId($baseValue, (int) $treeId);

$store = new FileMatchStore($dir);

MatchSeeder::seed($store, $person, $status, $band, $death);

fwrite(STDOUT, sprintf('Seeded synthetic %s match for %s into %s', $band, $person, $dir) . PHP_EOL);
