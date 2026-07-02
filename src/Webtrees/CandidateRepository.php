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
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;

use function date;
use function is_string;
use function sort;

use const SORT_STRING;

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
     * Lazily yield every searchable candidate in the tree — an old individual without an
     * interpretable death date, visible to the current user, whose latest possible birth still
     * implies an age of at least {@see CandidateCriteria::$minAge} — in lexicographic xref order.
     *
     * The xref pre-filter — a single cheap SQL SELECT that returns only id strings, no hydrated
     * individual — is materialised and sorted up front, but each surviving xref is hydrated to an
     * {@see Individual}, privacy-gated and PHP age-rechecked only when the consumer pulls the next
     * value. So a consumer that stops early (the enqueue producer capping to its `--limit`) pays
     * `O(consumed)` hydrations rather than eagerly hydrating the whole eligible population. A caller
     * that wants the whole set simply drains the generator (`iterator_to_array(...)`).
     *
     * The yield order is fixed in PHP (a `SORT_STRING` over the plucked xrefs, NOT a SQL `ORDER BY`),
     * so the producer's lowest-xref-first cap is deterministic and identical on every database engine.
     * The order is lexicographic by design (byte-wise), NOT numeric — so for the rare tree carrying
     * bare-numeric xrefs the cap tiebreak is lexicographic (`"10"` sorts before `"2"`);
     * webtrees-generated xrefs are always letter-prefixed, where lexicographic and numeric order
     * coincide.
     *
     * @param Tree              $tree     The tree to search.
     * @param CandidateCriteria $criteria The selection criteria.
     *
     * @return Generator<int, PersonCandidate> The surviving candidates, in lexicographic xref order.
     */
    public function findCandidatesLazily(Tree $tree, CandidateCriteria $criteria): Generator
    {
        // Resolve the reference year once, at the top, so the whole selection measures age
        // against a single fixed point rather than re-reading the clock per row.
        $referenceYear = $criteria->referenceYear ?? (int) date('Y');
        $minAge        = $criteria->minAge;

        foreach ($this->selectCandidateXrefs($tree, $criteria, $referenceYear) as $xref) {
            $candidate = $this->resolveCandidate($tree, $xref);

            if (!$candidate instanceof PersonCandidate) {
                continue;
            }

            if (!$this->isWithinAgeWindow($candidate, $referenceYear, $minAge, $criteria->maxAge, $criteria->includeUnknownBirth)) {
                continue;
            }

            yield $candidate;
        }
    }

    /**
     * Run the portable SQL pre-filter and return the surviving xrefs, sorted lexicographically. This
     * is the cheap half of the selection: only id strings cross the boundary — no individual is
     * hydrated — so a bounded consumer can sort, skip the in-flight ids and cap at the xref level
     * before paying for a single hydration. The ordering is applied here in PHP (byte-wise
     * `SORT_STRING`) rather than as a SQL `ORDER BY`, so it is lexicographic and identical on every
     * engine, with no dependence on the storage engine's column collation.
     *
     * @param Tree              $tree          The tree to search.
     * @param CandidateCriteria $criteria      The selection criteria.
     * @param int               $referenceYear The resolved reference year the age bound measures against.
     *
     * @return list<string> The surviving xrefs, sorted lexicographically.
     */
    private function selectCandidateXrefs(Tree $tree, CandidateCriteria $criteria, int $referenceYear): array
    {
        $birthCeiling = $referenceYear - $criteria->minAge;

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
            // re-check tightens it against the latest possible birth.
            $query->whereExists(static function (Builder $subQuery) use ($birthCeiling): void {
                $subQuery
                    ->select(new Expression('1'))
                    ->from('dates')
                    ->whereColumn('dates.d_file', 'individuals.i_file')
                    ->whereColumn('dates.d_gid', 'individuals.i_id')
                    // Match every event webtrees treats as a birth ({@see Gedcom::BIRTH_EVENTS}
                    // = BIRT/CHR/BAPM), the same set `Individual::getBirthDate()` and the PHP
                    // re-check honour: a christening/baptism-only individual (common for the
                    // oldest parish-record people) has no `BIRT` dates row and would otherwise
                    // be silently dropped before reaching the PHP layer.
                    ->whereIn('dates.d_fact', Gedcom::BIRTH_EVENTS)
                    ->where('dates.d_year', '>', 0)
                    ->where('dates.d_year', '<=', $birthCeiling);
            });
        }

        /** @var list<mixed> $rows */
        $rows = $query->pluck('i_id')->all();

        $xrefs = [];

        foreach ($rows as $xref) {
            if (!is_string($xref)) {
                continue;
            }

            if ($xref === '') {
                continue;
            }

            $xrefs[] = $xref;
        }

        sort($xrefs, SORT_STRING);

        return $xrefs;
    }

    /**
     * Rebuild the candidates for an explicit xref set, keyed by id, bypassing the
     * age/death selection filter {@see findCandidatesLazily()} applies. Unlike a fresh selection
     * this rebuilds whoever was requested — a draining caller already holds the xrefs and
     * needs their live candidate shape, not a re-evaluation of who qualifies. An xref with
     * no individual, or one the current user may not see, is silently omitted.
     *
     * @param Tree         $tree  The tree the xrefs belong to.
     * @param list<string> $xrefs The xrefs to rebuild candidates for.
     *
     * @return array<string, PersonCandidate> The surviving candidates, keyed by their id.
     */
    public function findByXrefs(Tree $tree, array $xrefs): array
    {
        $candidates = [];

        foreach ($xrefs as $xref) {
            $candidate = $this->resolveCandidate($tree, $xref);

            if (!$candidate instanceof PersonCandidate) {
                continue;
            }

            $candidates[$candidate->id] = $candidate;
        }

        return $candidates;
    }

    /**
     * Resolve a single xref to a pure {@see PersonCandidate} through the package's one
     * webtrees seam: materialise the individual, then map it via {@see PersonCandidateAdapter}
     * — which returns null for a record the current user may not see, the package's single
     * privacy gate. Returns null when no individual exists or the privacy gate hides it.
     *
     * @param Tree   $tree The tree the xref belongs to.
     * @param string $xref The xref to resolve.
     *
     * @return PersonCandidate|null The mapped candidate, or null when it cannot be built.
     */
    private function resolveCandidate(Tree $tree, string $xref): ?PersonCandidate
    {
        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return PersonCandidateAdapter::fromIndividual($individual);
    }

    /**
     * Privacy-conservative age-window re-check: age is measured against the candidate's LATEST possible
     * birth — i.e. the YOUNGEST the person can be. That keeps the lower bound conservative (only the
     * certainly-old-enough pass: a `BET 1936 AND 1940` birth is dropped because its youngest reading, 1940,
     * is too young even though its 1936 lower bound cleared the coarse SQL `d_year` bound) and the optional
     * upper bound inclusive-when-uncertain (only the certainly-too-old are dropped), which is the right
     * default for a search filter that must not silently miss a searchable person. A candidate without any
     * known birth survives only when unknown births are explicitly requested.
     *
     * @param PersonCandidate $candidate           The candidate to re-check.
     * @param int             $referenceYear       The year age is measured against.
     * @param int             $minAge              The minimum age in years.
     * @param int|null        $maxAge              The maximum age in years, or null for no upper bound.
     * @param bool            $includeUnknownBirth Whether an unknown birth still qualifies.
     *
     * @return bool Whether the candidate falls within the requested age window.
     */
    private function isWithinAgeWindow(
        PersonCandidate $candidate,
        int $referenceYear,
        int $minAge,
        ?int $maxAge,
        bool $includeUnknownBirth,
    ): bool {
        $latestBirthYear = $candidate->birth->latest?->year;

        if ($latestBirthYear === null) {
            return $includeUnknownBirth;
        }

        $youngestAge = $referenceYear - $latestBirthYear;

        if ($youngestAge < $minAge) {
            return false;
        }

        return ($maxAge === null)
            || ($youngestAge <= $maxAge);
    }

    /**
     * Counts the candidates a selection would search, honouring the SAME privacy gate, age window and
     * unknown-birth rule as {@see self::findCandidatesLazily()} — it drains that generator, so the count
     * can never over-report a privacy-hidden or too-young/too-old individual. Used for the worklist's
     * "≈ N people match" selection preview (#63).
     *
     * The count is BOUNDED by $limit: it stops hydrating the moment $limit candidates are reached and
     * returns $limit, so a preview on a very large tree can never drain the whole eligible population into
     * an unbounded hydration loop (memory stays flat — the generator streams one candidate at a time — and
     * the walk is capped in time). A caller that gets back exactly $limit renders the count as "$limit+".
     *
     * The dominant cost — hydrating an {@see Individual}, its privacy gate and its date re-check per
     * candidate — is what the cap bounds. The cheap SQL pre-filter still materialises the matching xref
     * STRINGS (bounded by the tree, and, with includeUnknownBirth, potentially most of it), because those
     * ids feed the deterministic lowest-xref-first ordering the enqueue path shares and cannot be
     * SQL-LIMITed without under-counting (the privacy + age re-check run in PHP after the query). That id
     * list is orders of magnitude lighter than hydrating them, and the whole path is manager-gated and
     * runs only on an explicit preview.
     *
     * @param Tree              $tree     The tree whose candidates are counted.
     * @param CandidateCriteria $criteria The selection criteria.
     * @param int               $limit    The maximum candidates to count before stopping (a defensive cap).
     *
     * @return int The number of matching individuals, capped at $limit.
     */
    public function countCandidates(Tree $tree, CandidateCriteria $criteria, int $limit): int
    {
        // A non-positive cap counts nothing (mirrors EnqueueService::enqueue()'s `$limit < 1` guard): the
        // `$count >= $limit` break below would otherwise fire only AFTER the first ++, wrongly returning 1.
        if ($limit <= 0) {
            return 0;
        }

        $count = 0;

        foreach ($this->findCandidatesLazily($tree, $criteria) as $ignoredCandidate) {
            ++$count;

            if ($count >= $limit) {
                break;
            }
        }

        return $count;
    }
}
