<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Headless CLI adapter that undoes ONE confirmed write-back for a single (tree, person, obituary)
 * triple. It is a THIN composition root mirroring {@see tools/enqueue.php}: it boots the request-less
 * webtrees runtime ({@see HeadlessBootstrap}), logs in the system principal so every fact is visible,
 * resolves the confirmed store row, deletes the facts the confirm wrote ({@see WriteBackReverter}) and
 * — only when no module-written fact still stands in the tree ({@see RevertConsistencyGate}) — returns
 * the store row to Pending ({@see MatchStore::revert()}). All domain logic lives in the injected
 * services; this file only parses options, assembles the graph, applies the store-transition gate and
 * maps the outcome to an exit code.
 *
 * `--tree` is REQUIRED: it is the NUMERIC webtrees tree id (the integer primary key). `--person` is
 * the candidate XREF and `--url` the source obituary URL (both required). `--force` (a no-value flag)
 * best-effort deletes whichever recorded facts still resolve and tolerates a record that was already
 * removed out-of-band (orphan repair); without it the revert is all-or-nothing.
 *
 * Usage:
 *   php tools/revert.php --tree=1 --person=X123 --url=https://example/notice [--force]
 *
 * Exit codes: 0 on a completed revert (facts removed AND the store returned to Pending), 1 when the
 * boot fails, the DB is unreachable, an option is missing/malformed, the tree id is unknown, no
 * confirmed revertable row exists, the recorded write-back is corrupt, the all-or-nothing precondition
 * is unmet, a `--force` mixed partial leaves an edited fact standing, or the store transition fails.
 *
 * Error messages NEVER echo the raw obituary URL (S46 privacy): only the person XREF and numeric tree
 * id identify the row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;
use MagicSunday\ObituaryMatcher\Webtrees\RevertConsistencyGate;
use MagicSunday\ObituaryMatcher\Webtrees\RevertPreconditionException;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackReverter;

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
    'person:',      // required → single colon
    'url:',         // required → single colon
    'force',        // no-value flag → no colon
]);
$options = $options === false ? [] : $options;

$treeOption   = $options['tree'] ?? null;
$personOption = $options['person'] ?? null;
$urlOption    = $options['url'] ?? null;

// --force is a no-value flag: getopt() yields it as `false` when present and omits the key otherwise.
$force = array_key_exists('force', $options);

// --tree is REQUIRED and must be numeric: the revert targets exactly one tree. A missing, non-string
// (the required-value `:` form passed without `=`), or non-numeric --tree is a misuse; fail loud
// rather than coercing it to tree-0 via the (int) cast.
if (
    !is_string($treeOption)
    || !ctype_digit($treeOption)
) {
    fwrite(STDERR, '--tree=<numeric id> is required (the tree the confirm was written to).' . PHP_EOL);

    exit(1);
}

$treeId = (int) $treeOption;

// --person is REQUIRED and must be a non-empty string. A non-string is the required-value `:` form
// passed without `=`; an empty string is a misuse. Fail loud rather than resolving a blank XREF.
if (
    !is_string($personOption)
    || ($personOption === '')
) {
    fwrite(STDERR, '--person=<xref> is required (the candidate the confirm was written to).' . PHP_EOL);

    exit(1);
}

$personId = $personOption;

// --url is REQUIRED and must be a non-empty string (the row key is derived from it). A non-string is
// the required-value `:` form passed without `=`. The raw value is never echoed back (S46 privacy).
if (
    !is_string($urlOption)
    || ($urlOption === '')
) {
    fwrite(STDERR, '--url=<obituary url> is required (the source notice the confirm was made from).' . PHP_EOL);

    exit(1);
}

$url = $urlOption;

// Boot the request-less webtrees runtime and log in the system principal so the revert sees every
// fact. A boot failure (missing config, no admin account, …) is unrecoverable: the shared
// HeadlessBootstrap::bootForCli() reports the fixed category WITHOUT leaking the DSN/credentials,
// routes the raw detail to the guarded error_log sink (the privacy-critical S46 handling) and exits
// non-zero. The PDOException-first arm ordering and the guarded sink live in that shared method.
HeadlessBootstrap::bootForCli('revert', new UserService());

$treeService = new TreeService(new GedcomImportService());

try {
    $tree = $treeService->find($treeId);
} catch (DomainException) {
    // An unknown/vanished tree id: a fixed error + non-zero exit (the tree binding is required).
    fwrite(STDERR, sprintf('Unknown tree id: %d', $treeId) . PHP_EOL);

    exit(1);
}

$store = MatchStoreFactory::forTree($tree);
$row   = $store->findOne($personId, StoredMatchKey::fromUrl($url));

// The revert only applies to a confirmed row that recorded a write-back. A missing row, a non-confirmed
// row, or a confirmed row with no write-back is a no-op misuse — fixed error, no raw URL.
if (
    !$row instanceof StoredMatch
    || ($row->status !== MatchStatus::Confirmed)
    || ($row->writeBack === null)
) {
    fwrite(STDERR, sprintf('No confirmed, revertable match for person %s at the given URL in tree %d.', $personId, $treeId) . PHP_EOL);

    exit(1);
}

$individual = Registry::individualFactory()->make($personId, $tree);

if (!$individual instanceof Individual) {
    fwrite(STDERR, sprintf('Individual %s does not exist in tree %d.', $personId, $treeId) . PHP_EOL);

    exit(1);
}

// A corrupt/hand-edited writeBack row must fail cleanly, not with a trace.
try {
    $writeBack = WriteBack::fromArray($row->writeBack);
} catch (InvalidArgumentException) {
    fwrite(STDERR, sprintf('The confirmed match for person %s has an invalid write-back record.', $personId) . PHP_EOL);

    exit(1);
}

// The target ids this revert is responsible for (DEAT always, BURI when one was written).
$targetCount = 1 + (int) ($writeBack->buriFactId !== null);

// Block A — the GEDCOM revert. NORMAL mode refuses (deletes nothing) if any target was edited/removed.
try {
    $result = (new WriteBackReverter())->revert($individual, $writeBack, $force);
} catch (RevertPreconditionException) {
    fwrite(STDERR, 'Revert refused: a written fact was edited or already removed (use --force to override).' . PHP_EOL);

    exit(1);
}

$deletedCount = count($result->deletedFactIds);

// Store-transition consistency gate: only return the row to Pending when NO module-written fact still
// stands in the tree. A clean revert deletes every target; deletedCount === 0 under --force is
// orphan-repair (the recorded facts are not present). A mixed partial (--force deleted SOME but an
// edited target remains) must NOT flip the store to Pending — that would be a false truth.
if (!RevertConsistencyGate::isConsistent($targetCount, $deletedCount, $force)) {
    fwrite(STDERR, sprintf('Revert partially completed for person %s (%d of %d facts); the store was left unchanged.', $personId, $deletedCount, $targetCount) . PHP_EOL);

    exit(1);
}

// Block B — the store transition AFTER a consistent GEDCOM revert (orphan-risk path, symmetric to
// confirm: the facts are already gone, so a transition failure leaves the store out of sync). Log the
// orphan for an administrator and surface it; never report it as success.
try {
    $store->revert($personId, $url);
} catch (Throwable $throwable) {
    Log::addErrorLog('Obituary matcher: revert deleted the facts but the store transition failed: ' . $throwable->getMessage());
    fwrite(STDERR, sprintf('The facts were reverted but the store could not be updated for person %s; re-run with --force.', $personId) . PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf('reverted=%d person=%s tree=%d', $deletedCount, $personId, $treeId) . PHP_EOL);

exit(0);
