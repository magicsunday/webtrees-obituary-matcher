<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;

/**
 * The per-person coverage persistence boundary, separate from the {@see MatchStore} (which stores per
 * NOTICE): the drain records the finder's per-portal coverage for each requested person so the UI can
 * later tell a genuine miss (all portals searched, nothing found) from a portal outage. Kept a distinct
 * seam so the many MatchStore implementations are not forced to carry a coverage concern they do not
 * own, and so a consumer needing only coverage depends on just this.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface CoverageStore
{
    /**
     * Records (last-write-wins) the per-portal coverage a finder reported for one person in a drain run.
     *
     * @param string               $personId The requested person the coverage belongs to.
     * @param list<PortalCoverage> $coverage The per-portal coverage for that person.
     *
     * @return void
     */
    public function record(string $personId, array $coverage): void;

    /**
     * Returns the last-recorded per-portal coverage for a person, or an empty list when none was
     * recorded (the person was never searched, or the record is absent/corrupt).
     *
     * @param string $personId The person whose coverage is read.
     *
     * @return list<PortalCoverage> The recorded coverage.
     */
    public function findByPerson(string $personId): array;
}
