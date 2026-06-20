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
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;

use function date;

/**
 * Selects the individuals worth searching an obituary for: old people whose death date is
 * still missing, gated privacy-conservatively.
 *
 * The heavy lifting happens in a single portable SQL pre-filter (correlated
 * `whereExists` / `whereNotExists` subqueries — no grouped non-aggregated column, so it is
 * SQLite and MySQL `ONLY_FULL_GROUP_BY`-safe), after which every surviving row is resolved
 * to a webtrees individual, dropped when the current user may not see it, mapped to a pure
 * {@see PersonCandidate} via {@see PersonCandidateAdapter}, and finally re-checked in PHP:
 * the coarse `d_year` column cannot tell an `ABT`/`BET` upper bound apart from an exact
 * year, so the age bound is re-applied against the candidate's LATEST possible birth.
 *
 * This is the only repository in the package; together with {@see PersonCandidateAdapter}
 * and {@see WebtreesDateMapper} it forms the entire `Fisharebest\Webtrees`-coupled layer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CandidateRepository
{
    /**
     * Find every searchable candidate in the tree: an old individual without an
     * interpretable death date, visible to the current user, whose latest possible birth
     * still implies an age of at least {@see CandidateCriteria::$minAge}.
     *
     * @param Tree              $tree     The tree to search.
     * @param CandidateCriteria $criteria The selection criteria.
     *
     * @return list<PersonCandidate> The surviving candidates, in lexicographic xref order.
     */
    public function findCandidates(Tree $tree, CandidateCriteria $criteria): array
    {
        // Resolve the reference year once, at the top, so the whole query measures age
        // against a single fixed point rather than re-reading the clock per row.
        $referenceYear = $criteria->referenceYear ?? (int) date('Y');
        $minAge        = $criteria->minAge;
        $birthCeiling  = $referenceYear - $minAge;

        $query = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            // Exclude anyone with ANY interpretable death date: a bare `1 DEAT` or
            // `1 DEAT Y` carries no `2 DATE` and therefore no dates row, so it stays a
            // candidate, but a `DEAT` with an exact, `ABT` or range date does not.
            ->whereNotExists(static function (Builder $subQuery): void {
                $subQuery
                    ->select(new Expression('1'))
                    ->from('dates')
                    ->whereColumn('dates.d_file', 'individuals.i_file')
                    ->whereColumn('dates.d_gid', 'individuals.i_id')
                    // Deliberately keyed on `DEAT` only — unlike the widened birth set
                    // below. A person with only a `BURI`/`CREM` date but no death date is
                    // still "missing a death date" and worth searching, so the asymmetry is
                    // intentional: do NOT widen this to the burial/cremation events.
                    ->where('dates.d_fact', '=', 'DEAT')
                    ->where(static function (Builder $dateQuery): void {
                        $dateQuery
                            ->where('dates.d_julianday1', '<>', 0)
                            ->orWhere('dates.d_julianday2', '<>', 0)
                            ->orWhere('dates.d_year', '<>', 0);
                    });
            });

        if (!$criteria->includeUnknownBirth) {
            // Require a birth date old enough to clear the age bound. The `d_year` column
            // is coarse for imprecise dates, so this only narrows the set — the PHP
            // re-check below tightens it against the latest possible birth.
            $query->whereExists(static function (Builder $subQuery) use ($birthCeiling): void {
                $subQuery
                    ->select(new Expression('1'))
                    ->from('dates')
                    ->whereColumn('dates.d_file', 'individuals.i_file')
                    ->whereColumn('dates.d_gid', 'individuals.i_id')
                    // Match every event webtrees treats as a birth ({@see Gedcom::BIRTH_EVENTS}
                    // = BIRT/CHR/BAPM), the same set `Individual::getBirthDate()` and the PHP
                    // re-check below honour: a christening/baptism-only individual (common for
                    // the oldest parish-record people) has no `BIRT` dates row and would
                    // otherwise be silently dropped before reaching the PHP layer.
                    ->whereIn('dates.d_fact', Gedcom::BIRTH_EVENTS)
                    ->where('dates.d_year', '>', 0)
                    ->where('dates.d_year', '<=', $birthCeiling);
            });
        }

        $rows = $query->select(['i_id'])->get();

        $candidates = [];

        foreach ($rows as $row) {
            /** @var array{i_id?: string} $typedRow */
            $typedRow = (array) $row;

            $xref = $this->xref($typedRow);

            if ($xref === '') {
                continue;
            }

            $individual = Registry::individualFactory()->make($xref, $tree);

            if (!$individual instanceof Individual) {
                continue;
            }

            // PersonCandidateAdapter drops a record the current user may not see (null),
            // which is the package's single privacy gate.
            $candidate = PersonCandidateAdapter::fromIndividual($individual);

            if (!$candidate instanceof PersonCandidate) {
                continue;
            }

            if (!$this->isOldEnough($candidate, $referenceYear, $minAge, $criteria->includeUnknownBirth)) {
                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * Read the `i_id` xref from an Eloquent result row, cast to a typed array shape at the
     * boundary, collapsing a missing value to an empty string the caller skips.
     *
     * @param array{i_id?: string} $row The result row from the `individuals` query.
     *
     * @return string The xref, or an empty string when absent.
     */
    private function xref(array $row): string
    {
        return $row['i_id'] ?? '';
    }

    /**
     * Privacy-conservative age re-check: keep a candidate only when its LATEST possible
     * birth still implies an age of at least the minimum. This drops e.g. a
     * `BET 1936 AND 1940` birth whose upper bound (1940) is too young even though its
     * lower bound passed the coarse SQL `d_year` bound. A candidate without any known
     * birth survives only when unknown births are explicitly requested.
     *
     * @param PersonCandidate $candidate           The candidate to re-check.
     * @param int             $referenceYear       The year age is measured against.
     * @param int             $minAge              The minimum age in years.
     * @param bool            $includeUnknownBirth Whether an unknown birth still qualifies.
     *
     * @return bool Whether the candidate is old enough to keep.
     */
    private function isOldEnough(
        PersonCandidate $candidate,
        int $referenceYear,
        int $minAge,
        bool $includeUnknownBirth,
    ): bool {
        $latestBirthYear = $candidate->birth->latest?->year;

        if ($latestBirthYear === null) {
            return $includeUnknownBirth;
        }

        return ($referenceYear - $latestBirthYear) >= $minAge;
    }
}
