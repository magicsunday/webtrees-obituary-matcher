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
     * @param Tree $tree The tree to read.
     *
     * @return list<array{xref: string, gedcom: string}> The accepted source rows.
     */
    public function acceptedSources(Tree $tree): array
    {
        $rows = DB::table('sources')
            ->where('s_file', '=', $tree->id())
            ->orderBy('s_id')
            ->select(['s_id', 's_gedcom'])
            ->get();

        $sources = [];

        foreach ($rows as $row) {
            $xref   = $row->s_id;
            $gedcom = $row->s_gedcom;

            if (!is_string($xref)) {
                continue;
            }

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
     * `{xref, gedcom}` tuple, in change order so the earliest pending source wins a duplicate REFN.
     *
     * @param Tree $tree The tree to read.
     *
     * @return list<array{xref: string, gedcom: string}> The pending source rows.
     */
    public function pendingSources(Tree $tree): array
    {
        $rows = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->orderBy('change_id')
            ->select(['xref', 'new_gedcom'])
            ->get();

        $sources = [];

        foreach ($rows as $row) {
            $xref   = $row->xref;
            $gedcom = $row->new_gedcom;

            if (!is_string($xref)) {
                continue;
            }

            if (!is_string($gedcom)) {
                continue;
            }

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
}
