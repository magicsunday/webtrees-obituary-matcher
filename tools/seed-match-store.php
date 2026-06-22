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
 * Usage:
 *   php tools/seed-match-store.php --tree-id=1 --person=I1 \
 *       [--status=pending] [--band=strong] [--death=2023-09-04] [--data-path=/path/to/data]
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use Fisharebest\Webtrees\Webtrees;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;

// This file lives in the global namespace, so `use function`/`use const` for built-ins is a no-op
// that emits a warning under newer PHP; the built-ins are referenced unqualified directly, matching
// the global-namespace entry-point convention used by module.php.

require __DIR__ . '/../.build/vendor/autoload.php';

$options = getopt('', [
    'tree-id:',
    'person:',
    'status::',
    'band::',
    'death::',
    'data-path::',
]);

$treeId = $options['tree-id'] ?? null;
$person = $options['person'] ?? null;

if (
    !is_string($treeId)
    || !is_string($person)
) {
    fwrite(STDERR, 'Both --tree-id (numeric tree id) and --person (XREF) are required.' . PHP_EOL);

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

$baseValue = $options['data-path'] ?? Webtrees::DATA_DIR . 'obituary-matcher/matches';
$base      = is_string($baseValue) ? $baseValue : Webtrees::DATA_DIR . 'obituary-matcher/matches';

// Mirror MatchStoreFactory::pathForTree without a Tree object: the CLI has only the numeric id.
$dir = rtrim($base, '/') . '/tree-' . $treeId;

$store = new FileMatchStore($dir);

MatchSeeder::seed($store, $person, $status, $band, $death);

fwrite(STDOUT, sprintf('Seeded synthetic %s match for %s into %s', $band, $person, $dir) . PHP_EOL);
