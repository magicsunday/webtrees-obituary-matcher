<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;

/**
 * Projects the in-flight jobIds from a {@see RestPendingLedger}'s poison-tolerant `entries()` union scan.
 * This is the test-side replacement for the removed production `RestPendingLedger::jobIds()` accessor,
 * which had no production caller (the ledger consumers iterate `entries()` directly). Shared by the two
 * Queue tests that assert the ledger's in-flight jobId list.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
trait ProjectsLedgerJobIds
{
    /**
     * Projects the jobId of every well-formed in-flight entry, in `entries()` scan order.
     *
     * @param RestPendingLedger $ledger The ledger whose in-flight jobIds to project.
     *
     * @return list<string> The in-flight jobIds.
     */
    private static function jobIdsOf(RestPendingLedger $ledger): array
    {
        $ids = [];

        foreach ($ledger->entries() as $entry) {
            $ids[] = $entry['jobId'];
        }

        return $ids;
    }
}
