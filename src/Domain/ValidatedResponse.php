<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * The decoded, trusted result of validating a finder response: the death notices AND the per-portal
 * coverage, each keyed by the requested person id. The contract makes `coverage` required alongside
 * `notices` precisely so the matcher can tell a genuine miss (all portals searched, 0 notices) from a
 * portal outage (a `failed` portal) — carried here together so no consumer sees notices without the
 * coverage that qualifies them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ValidatedResponse
{
    /**
     * Constructor.
     *
     * @param array<string, list<DeathNoticeRecord>> $notices  The validated notices, keyed by person id.
     * @param array<array-key, list<PortalCoverage>> $coverage The per-portal coverage, keyed by person
     *                                                         id (array-key, not string: PHP coerces a
     *                                                         purely-numeric XREF key back to int).
     */
    public function __construct(
        public array $notices,
        public array $coverage,
    ) {
    }
}
