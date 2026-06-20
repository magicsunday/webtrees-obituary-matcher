<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

/**
 * The selection criteria for {@see CandidateRepository::findCandidates()}: which old
 * individuals without a certain death date are worth searching an obituary for.
 *
 * Phase 2a is a forward search for MISSING death dates, so there is deliberately no
 * `includeKnownDeathDate` flag — a person with an interpretable death date is never a
 * candidate. The reference year is injectable so tests stay reproducible; left null it
 * defaults to the current year, resolved once inside the repository rather than read deep
 * in the query.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CandidateCriteria
{
    /**
     * Constructor.
     *
     * @param int      $minAge              Minimum age (in years) an individual must have reached by
     *                                      the reference year to be searchable.
     * @param bool     $includeUnknownBirth Whether to also surface individuals without any known birth
     *                                      date, which the age bound cannot otherwise vouch for.
     * @param int|null $referenceYear       The year the age is measured against, or null to default to
     *                                      the current year at query time.
     */
    public function __construct(
        public int $minAge = 90,
        public bool $includeUnknownBirth = false,
        public ?int $referenceYear = null,
    ) {
    }
}
