<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;

use function array_filter;
use function array_values;
use function implode;
use function trim;
use function usort;

/**
 * Builds prioritised, deduped, plain-text search queries from a person candidate.
 *
 * The generator is a pure, instantiable service (no constructor, no I/O) so a later
 * request factory can inject it. Each query is assembled from non-empty name/date/place
 * parts joined by single spaces; queries carry only plain text — never quotes, search
 * operators or keywords.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class QueryGenerator
{
    /**
     * Generates the prioritised, deduplicated list of plain-text queries for a candidate.
     *
     * Candidates are produced in priority order (1 = most specific), deduplicated by their
     * normalised full query keeping the lowest-priority-number occurrence, then returned
     * sorted ascending by priority.
     *
     * @param PersonCandidate $candidate The candidate to derive queries from.
     *
     * @return list<CandidateQuery> The deduplicated queries sorted ascending by priority.
     */
    public function generate(PersonCandidate $candidate): array
    {
        $given        = implode(' ', $candidate->name->givenNames);
        $callName     = $candidate->name->callName ?? '';
        $surname      = $candidate->name->surname;
        $birthSurname = $candidate->name->birthSurname ?? '';
        $year         = $this->birthYear($candidate);
        $place        = $this->firstPlace($candidate);

        // The everyday name form used for the broader queries: the call name when known,
        // otherwise the full given-name string.
        $name = $callName !== '' ? $callName : $given;

        /** @var list<array{0:string,1:int}> $candidates Each entry is [assembled query, priority]. */
        $candidates = [];

        // One married-surname pair of tiers per married surname, so a person with two or
        // more married names yields a standalone query for each instead of one blob token.
        // A person without any married surname still emits the degenerate given+year /
        // given+place tiers (the empty surname is skipped by assemble()), preserving the
        // pre-existing single-married-surname output exactly.
        $marriedSurnames = $candidate->name->marriedSurnames !== [] ? $candidate->name->marriedSurnames : [''];

        foreach ($marriedSurnames as $married) {
            $candidates[] = [$this->assemble($given, $married, $year), 1];
            $candidates[] = [$this->assemble($given, $married, $place), 3];
        }

        $candidates[] = [$this->assemble($given, $birthSurname, $year), 2];
        $candidates[] = [$this->assemble($callName, $surname), 4];
        $candidates[] = [$this->assemble($given, $birthSurname), 5];
        $candidates[] = [$this->assemble($name, $surname, $year), 6];
        $candidates[] = [$this->assemble($name, $surname, $place), 7];

        /** @var array<string, CandidateQuery> $byKey */
        $byKey = [];

        foreach ($candidates as [$query, $priority]) {
            if ($query === '') {
                continue;
            }

            $dedupKey = Normalizer::strip($query);

            // Keep the first (lowest-priority-number) occurrence per dedup key.
            if (isset($byKey[$dedupKey])) {
                continue;
            }

            $byKey[$dedupKey] = new CandidateQuery($query, $priority, $dedupKey);
        }

        $queries = array_values($byKey);

        usort(
            $queries,
            static fn (CandidateQuery $a, CandidateQuery $b): int => $a->priority <=> $b->priority,
        );

        return $queries;
    }

    /**
     * Assembles a query from the given parts, skipping empties and joining with single spaces.
     *
     * @param string ...$parts The candidate parts, any of which may be an empty string.
     *
     * @return string The trimmed, single-space-joined query (empty when no part survives).
     */
    private function assemble(string ...$parts): string
    {
        $nonEmpty = array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== '',
        );

        return implode(' ', array_values($nonEmpty));
    }

    /**
     * Returns the candidate's birth year as a string, or an empty string when unknown.
     *
     * @param PersonCandidate $candidate The candidate to read the birth year from.
     *
     * @return string The four-digit birth year, or an empty string when not known.
     */
    private function birthYear(PersonCandidate $candidate): string
    {
        $birth = $candidate->birth;

        if (
            !$birth->isKnown()
            || (!$birth->earliest instanceof DateValue)
        ) {
            return '';
        }

        return (string) $birth->earliest->year;
    }

    /**
     * Returns the first known place name, or an empty string when none is recorded.
     *
     * @param PersonCandidate $candidate The candidate to read the places from.
     *
     * @return string The first place's name, or an empty string when no place is recorded.
     */
    private function firstPlace(PersonCandidate $candidate): string
    {
        foreach ($candidate->places as $place) {
            if (trim($place->name) !== '') {
                return $place->name;
            }
        }

        return '';
    }
}
