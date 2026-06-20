<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateCriteria;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\PersonCandidateAdapter;
use MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function file_get_contents;
use function sort;

/**
 * Drives {@see CandidateRepository::findCandidates()} against a real imported tree and
 * proves every selection rule on its own discriminator:
 *
 * - I1 (old, no death) is searchable and kept.
 * - I2 (exact death date) and I3 (`DEAT` / `ABT 2020`) are excluded by the SQL death
 *   filter — any interpretable death date disqualifies.
 * - I4 (born 2000) is excluded by the SQL birth bound.
 * - I5 (`BET 1936 AND 1940`) clears the coarse SQL `d_year` bound (its 1936 lower row)
 *   but is dropped by the PHP re-check, because its LATEST possible birth (1940) is too
 *   young for the 2026 reference year at minAge 90.
 * - I6 (`1 DEAT Y`, no date) is kept — a death flag without a date is not an
 *   interpretable death date.
 * - I7 (confidential) is excluded by the privacy gate when viewed as a visitor, proven
 *   non-vacuous by first showing the admin context returns it.
 * - I8 (no birth date) is surfaced only when `includeUnknownBirth` is set.
 * - I9 (`1 CHR` christening, no `BIRT`) is kept: webtrees derives the birth date from the
 *   christening, so the SQL birth pre-filter must match all {@see Gedcom::BIRTH_EVENTS}, not
 *   just `BIRT`, or the oldest parish-record people would be silently dropped.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CandidateRepository::class)]
#[UsesClass(CandidateCriteria::class)]
#[UsesClass(PersonCandidateAdapter::class)]
#[UsesClass(WebtreesDateMapper::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\RufnameParser::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PersonName::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DateRange::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DateValue::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DatePrecision::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\Gender::class)]
final class CandidateRepositoryTest extends IntegrationTestCase
{
    /**
     * The reference year the suite measures age against; fixed so the fixture's
     * birth years stay reproducible regardless of the wall clock.
     */
    private const int REFERENCE_YEAR = 2026;

    /**
     * Import the repository fixture. `MAX_ALIVE_AGE` is lowered so the dated old
     * people (born in the 1920s/30s) count as dead and are therefore visible to a
     * visitor — otherwise the visitor-context privacy assertion below could not tell
     * the privacy gate apart from webtrees treating an undated person as still living.
     *
     * @return Tree The imported fixture tree.
     */
    private function repositoryTree(): Tree
    {
        $gedcom = file_get_contents(__DIR__ . '/../fixtures/repository.ged');

        self::assertIsString($gedcom);

        $tree = $this->importFixtureTree($gedcom);

        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);
        $tree->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_USER);
        $tree->setPreference('MAX_ALIVE_AGE', '80');

        return $tree;
    }

    /**
     * Map a candidate list to its sorted xref set, so membership is asserted without
     * coupling to the engine-dependent lexicographic row order.
     *
     * @param list<PersonCandidate> $candidates The candidates to reduce.
     *
     * @return list<string> The sorted, distinct-by-construction xref set.
     */
    private function xrefs(array $candidates): array
    {
        $xrefs = array_map(static fn (PersonCandidate $candidate): string => $candidate->id, $candidates);

        sort($xrefs);

        return $xrefs;
    }

    /**
     * Finds old candidates without a death date and excludes individuals filtered by age, death, or privacy.
     */
    #[Test]
    public function selectsOldPeopleWithoutACertainDeathDate(): void
    {
        $tree = $this->repositoryTree();

        // As an admin every record is visible, including the confidential I7 — so the
        // age/death filter alone would keep it. Asserting it is present here makes the
        // visitor-context exclusion below a real privacy discriminator, not a vacuous
        // pass against an already-empty set.
        $adminCandidates = (new CandidateRepository())->findCandidates(
            $tree,
            new CandidateCriteria(minAge: 90, referenceYear: self::REFERENCE_YEAR),
        );

        self::assertSame(['I1', 'I6', 'I7', 'I9'], $this->xrefs($adminCandidates));

        // Drop to a visitor: the confidential I7 is now hidden by the privacy gate,
        // while the dated old people stay visible (MAX_ALIVE_AGE makes them dead).
        Auth::logout();

        $candidates = (new CandidateRepository())->findCandidates(
            $tree,
            new CandidateCriteria(minAge: 90, referenceYear: self::REFERENCE_YEAR),
        );

        // I1: old, no death            -> kept
        // I6: `1 DEAT Y`, no date       -> kept (no interpretable death date)
        // I2: exact death date          -> excluded by SQL
        // I3: `DEAT` / `ABT 2020`       -> excluded by SQL
        // I4: born 2000                 -> excluded by SQL birth bound
        // I5: `BET 1936 AND 1940`       -> excluded by the PHP latest-birth re-check
        // I7: confidential              -> excluded by the privacy gate
        // I8: no birth date             -> excluded (unknown births not requested)
        // I9: `1 CHR` christening 1900  -> kept (birth derived from the christening date)
        self::assertSame(['I1', 'I6', 'I9'], $this->xrefs($candidates));
    }

    /**
     * The includeUnknownBirth flag adds individuals with no birth date to the result set.
     */
    #[Test]
    public function includeUnknownBirthAlsoSurfacesPeopleWithoutABirthDate(): void
    {
        $tree = $this->repositoryTree();

        Auth::logout();

        $candidates = (new CandidateRepository())->findCandidates(
            $tree,
            new CandidateCriteria(minAge: 90, includeUnknownBirth: true, referenceYear: self::REFERENCE_YEAR),
        );

        // The no-birth-date I8 (kept visible via its `1 DEAT Y` flag) now joins the set;
        // I5 stays excluded because its known latest birth is still too young. I9 (christened
        // 1900, birth derived from the christening) stays in the set.
        self::assertSame(['I1', 'I6', 'I8', 'I9'], $this->xrefs($candidates));
    }
}
