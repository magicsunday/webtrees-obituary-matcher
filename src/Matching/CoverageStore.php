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
 * NOTICE): the drain records each finder's per-portal coverage for every requested person so the UI can
 * later tell a genuine miss (all portals searched, nothing found) from a portal outage. Keyed per
 * (person × finder) (§5.2c) so one finder's coverage never clobbers another's; a read UNIONS every
 * finder's coverage into one row per portal (a portal reads as covered if ANY finder covered it). Kept a
 * distinct seam so the many MatchStore implementations are not forced to carry a coverage concern they do
 * not own, and so a consumer needing only coverage depends on just this.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
interface CoverageStore
{
    /**
     * @var string The finder-identity key used when no finder identity is configured (a single-finder
     *             deployment or an arg-less test build), so the drain always records under a definite key.
     */
    public const string DEFAULT_FINDER_ID = '';

    /**
     * Records (last-write-wins for that finder) the per-portal coverage a finder reported for one person
     * in a drain run, leaving every OTHER finder's coverage for the person untouched.
     *
     * @param string               $personId The requested person the coverage belongs to.
     * @param string               $finderId The identity of the finder that reported this coverage.
     * @param list<PortalCoverage> $coverage The per-portal coverage for that person.
     *
     * @return void
     */
    public function record(string $personId, string $finderId, array $coverage): void;

    /**
     * Returns the person's per-portal coverage UNIONED across every finder that searched them — one row
     * per portal, `ok` if any finder covered it — or an empty list when none was recorded (the person was
     * never searched, or the records are absent/corrupt).
     *
     * @param string $personId The person whose coverage is read.
     *
     * @return list<PortalCoverage> The merged coverage, one row per portal.
     */
    public function findByPerson(string $personId): array;

    /**
     * Enumerates every searched person's coverage tree-wide, keyed by personId, with each person's rows
     * UNIONED across their finders exactly as {@see self::findByPerson} returns them. Lazily yielded so a
     * consumer can classify (and filter) each person without materialising the whole store. A person is
     * yielded once; a record that cannot be attributed to a definite person (a legacy/corrupt document
     * carrying no personId, or one whose stored personId does not match its location) is omitted rather
     * than surfaced under a wrong identity.
     *
     * @return iterable<string, list<PortalCoverage>> Each searched personId mapped to their merged per-portal coverage.
     */
    public function each(): iterable;
}
