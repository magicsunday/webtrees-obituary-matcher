<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;

use function is_string;
use function preg_match;
use function trim;

/**
 * The only SQL surface of the portal-source find-or-create. It reads the two row sets a
 * pending-aware scan must union — the accepted `sources` rows for a tree and the tree's
 * pending SOUR `change` rows — and hands them back as plain `{xref, gedcom}` tuples so that
 * {@see ObituaryWriteBack} can do the REFN matching without ever touching the query layer.
 *
 * Keeping every `DB::table(...)` call confined here honours the module's architecture rule
 * that only a `*Repository` may issue SQL; the adapter that orchestrates the write-back stays
 * query-free.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PortalSourceRepository
{
    /**
     * The match prefix that identifies a top-level SOUR record in a pending `change` row's GEDCOM.
     */
    private const string PENDING_SOURCE_REGEX = '/^0 @[^@]+@ ' . Source::RECORD_TYPE . '\b/';

    /**
     * Returns every accepted source of the tree as an `{xref, gedcom}` tuple, in stable id order.
     *
     * When a `$refn` is supplied the rows are narrowed in SQL to those whose gedcom contains the marker
     * substring, so a large tree's tens of thousands of unrelated accepted sources never enter PHP
     * memory. The constraint is a coarse pre-filter — the caller still confirms the marker on an anchored
     * `1 REFN` line, so a substring false positive is rejected there, not loaded as a match.
     *
     * @param Tree        $tree The tree to read.
     * @param string|null $refn The REFN marker to pre-filter on, or null to read every accepted source.
     *
     * @return list<array{xref: string, gedcom: string}> The accepted source rows.
     */
    public function acceptedSources(Tree $tree, ?string $refn = null): array
    {
        $query = DB::table('sources')
            ->where('s_file', '=', $tree->id());

        if ($refn !== null) {
            $query->where('s_gedcom', 'LIKE', '%' . $refn . '%');
        }

        // pluck()->all() keys the accepted gedcom by xref without ever exposing an Eloquent stdClass
        // row to this layer: the result is a plain array<string, scalar> the loop narrows to strings.
        $rows = $query
            ->orderBy('s_id')
            ->pluck('s_gedcom', 's_id')
            ->all();

        $sources = [];

        foreach ($rows as $xref => $gedcom) {
            // A purely numeric XREF (valid GEDCOM, common in imported trees) arrives as an int array key
            // because PHP casts numeric-string keys: cast back to string rather than drop it with an
            // is_string() guard that would silently skip the row.
            $xref = (string) $xref;

            if (!is_string($gedcom)) {
                continue;
            }

            $sources[] = [
                'xref'   => $xref,
                'gedcom' => $gedcom,
            ];
        }

        return $sources;
    }

    /**
     * Returns every pending SOUR change of the tree (records not yet in the `sources` table) as an
     * `{xref, gedcom}` tuple, in change order so the earliest pending source wins a duplicate REFN
     * across distinct xrefs.
     *
     * The webtrees `change` table is APPEND-ONLY: every create/update/delete of a record inserts a
     * NEW row carrying the same `xref`, so a record's pending state is a STACK ordered by `change_id`,
     * and only the LATEST row per xref is authoritative (a pending DELETE is `new_gedcom = ''`). This
     * mirrors core's {@see \Fisharebest\Webtrees\Factories\AbstractGedcomRecordFactory} resolution:
     * order by `change_id` ASC and `pluck()` the `new_gedcom` keyed by `xref`, so a later row
     * overwrites an earlier one for the same xref (latest wins). The collapsed map is then filtered —
     * a row whose latest blob is empty (pending delete) or no longer a portal SOUR record is dropped —
     * before it can resurrect a deleted source or surface a superseded blob.
     *
     * @param Tree $tree The tree to read.
     *
     * @return list<array{xref: string, gedcom: string}> The pending source rows.
     */
    public function pendingSources(Tree $tree): array
    {
        // pluck($value, $key) keyed by xref over the change_id-ordered stack collapses each xref's
        // append-only history to its latest blob (a later change_id overwrites the earlier entry),
        // exactly as core resolves a record's pending state — and never exposes an Eloquent stdClass.
        $latestByXref = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->orderBy('change_id')
            ->pluck('new_gedcom', 'xref')
            ->all();

        $sources = [];

        foreach ($latestByXref as $xref => $gedcom) {
            // A purely numeric XREF (valid GEDCOM, common in imported trees) arrives as an int array key
            // because PHP casts numeric-string keys: cast back to string rather than drop it with an
            // is_string() guard that would silently skip the row.
            $xref = (string) $xref;

            if (!is_string($gedcom)) {
                continue;
            }

            // A pending DELETE stores an empty (or whitespace-only) new_gedcom: the record's latest
            // pending state is "gone", so it must never be matched — dropping it closes the
            // create-then-delete resurrection.
            if (trim($gedcom) === '') {
                continue;
            }

            // After the per-xref fold this is the latest blob, so a create-then-edit row is filtered
            // on its current SOUR shape, not a superseded earlier one.
            if (preg_match(self::PENDING_SOURCE_REGEX, $gedcom) !== 1) {
                continue;
            }

            $sources[] = [
                'xref'   => $xref,
                'gedcom' => $gedcom,
            ];
        }

        return $sources;
    }

    /**
     * Returns the set of every xref carrying ANY pending change in the tree, regardless of the change's
     * latest blob — a pending edit, a pending create, OR a pending delete (empty `new_gedcom`) all count.
     *
     * This is the overlay key {@see ObituaryWriteBack::findPortalSource} uses to decide whether an
     * accepted source is superseded by a pending change: unlike {@see self::pendingSources}, it does NOT
     * drop the pending-delete / non-SOUR rows, because an accepted source with a pending DELETE must still
     * be skipped (its authoritative state is "deleted") even though it never appears in the match set.
     *
     * @param Tree $tree The tree to read.
     *
     * @return array<string, true> The set of xrefs with a pending change, keyed by xref.
     */
    public function pendingXrefs(Tree $tree): array
    {
        // pluck($value, $key) keyed by xref collapses the append-only stack to one entry per xref and,
        // like the sibling scans, never exposes an Eloquent stdClass: the result is array<xref, scalar>.
        $rows = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->pluck('xref', 'xref')
            ->all();

        $set = [];

        foreach ($rows as $xref => $ignored) {
            // A purely numeric XREF (valid GEDCOM, common in imported trees) arrives as an int array key
            // because PHP casts numeric-string keys: cast back to string so it matches the accepted
            // scan's string xref rather than being dropped by an is_string() guard.
            $set[(string) $xref] = true;
        }

        return $set;
    }
}
