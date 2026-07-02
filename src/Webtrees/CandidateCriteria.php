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
 * The selection criteria for {@see CandidateRepository::findCandidatesLazily()}: which old
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
     * The default minimum age (years) when none is configured — the privacy-conservative floor for a
     * "who is old enough to have a death notice" search.
     */
    public const int DEFAULT_MIN_AGE = 90;

    /**
     * The lowest age value a UI may offer for the age window.
     */
    public const int AGE_FLOOR = 0;

    /**
     * The highest age value a UI may offer for the age window.
     */
    public const int AGE_CEILING = 120;

    /**
     * Constructor.
     *
     * @param int      $minAge              Minimum age (in years) an individual must have reached by
     *                                      the reference year to be searchable.
     * @param bool     $includeUnknownBirth Whether to also surface individuals without any known birth
     *                                      date, which the age bound cannot otherwise vouch for.
     * @param int|null $referenceYear       The year the age is measured against, or null to default to
     *                                      the current year at query time.
     * @param int|null $maxAge              Maximum age (in years) by the reference year, or null for no
     *                                      upper bound — an optional window to exclude the implausibly old
     *                                      (e.g. a data-entry error) from a search. Applied conservatively:
     *                                      the age re-check uses the LATEST possible birth (the youngest the
     *                                      person can be), so an imprecise date is only excluded when its
     *                                      whole range exceeds the bound. It never loosens privacy — a
     *                                      person outside the window is simply not selected.
     */
    public function __construct(
        public int $minAge = self::DEFAULT_MIN_AGE,
        public bool $includeUnknownBirth = false,
        public ?int $referenceYear = null,
        public ?int $maxAge = null,
    ) {
    }
}
