<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\PersonName;

use function array_slice;
use function count;
use function implode;
use function mb_substr;
use function trim;

/**
 * The finder-request portion describing a single candidate and its prioritised queries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderCandidateRequest
{
    /**
     * The maximum number of contract name-entry forms per candidate (the schema's `maxItems`).
     */
    private const int MAX_NAME_ENTRIES = 10;

    /**
     * The maximum length of a NameEntry `full`/`given`/`surname` field (the #56 contract's `maxLength`).
     */
    private const int MAX_NAME_FIELD_LENGTH = 200;

    /**
     * The maximum length of a QueryHint `query` (the #56 contract's `maxLength`).
     */
    private const int MAX_QUERY_LENGTH = 500;

    /**
     * The maximum length of a QueryHint `dedupKey` (the #56 contract's `maxLength`).
     */
    private const int MAX_DEDUP_KEY_LENGTH = 200;

    /**
     * The maximum number of contract query hints per candidate (the schema's `maxItems`).
     */
    private const int MAX_QUERY_HINTS = 50;

    /**
     * Constructor.
     *
     * @param string               $personId      The candidate's stable identifier (e.g. the GEDCOM xref).
     * @param PersonName           $name          The decomposed name, projected onto contract name entries.
     * @param list<CandidateQuery> $queries       The prioritised, deduplicated plain-text queries.
     * @param list<string>         $excludedHosts The canonical hosts the candidate already has an open
     *                                            match on; a finder hint carried for the enqueue side, but
     *                                            NOT yet part of the published contract, hence off the wire.
     */
    public function __construct(
        public string $personId,
        public PersonName $name,
        public array $queries,
        public array $excludedHosts = [],
    ) {
    }

    /**
     * Serialises the candidate into the published `CandidateFacts` shape (see the #56 contract).
     *
     * Only the contract keys are emitted: the person reference, the projected `names`, and the
     * pre-built `queryHints`. The internal `excludedHosts` hint is intentionally omitted — a follow-up
     * will extend the contract to carry it.
     *
     * @return array{
     *   personId: string,
     *   names: list<array{kind?: string, full?: string, given?: string, surname?: string}>,
     *   queryHints: list<array{query: string, dedupKey: string, priority: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'personId'   => $this->personId,
            'names'      => $this->nameEntries(),
            'queryHints' => $this->queryHints(),
        ];
    }

    /**
     * Projects the decomposed {@see PersonName} onto contract name-entry forms — primary, birth,
     * married and alias — trimming each token and dropping any blank form, truncating each field to the
     * contract maximum ({@see self::MAX_NAME_FIELD_LENGTH}) and keeping at most
     * {@see self::MAX_NAME_ENTRIES} (primary first).
     *
     * MUST stay in lockstep with {@see PersonName::hasSearchableName()}: both decide a token's presence
     * by the SAME trimmed-non-blank test, and the enqueue producer excludes every candidate for which
     * that predicate is false, so a candidate that reaches here always carries at least one trimmed
     * token and the list is non-empty, satisfying the schema's `minItems: 1`. The producer contract test
     * pins that invariant at the boundary.
     *
     * @return list<array{kind?: string, full?: string, given?: string, surname?: string}> The projected name entries.
     */
    private function nameEntries(): array
    {
        $entries = [];

        // Trim every name token and drop the empty ones, so a whitespace-only or empty GEDCOM element
        // (e.g. `['', 'John']` or a `2 SURN "   "`) never reaches the wire as a leading-space or
        // blank value. This keeps the projection in lockstep with {@see PersonName::hasSearchableName()},
        // which decides inclusion on the SAME trimmed-non-empty notion.
        $cleanGivenNames = [];

        foreach ($this->name->givenNames as $givenName) {
            $trimmedGiven = trim($givenName);

            if ($trimmedGiven !== '') {
                $cleanGivenNames[] = $trimmedGiven;
            }
        }

        $given   = mb_substr(implode(' ', $cleanGivenNames), 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8');
        $surname = mb_substr(trim($this->name->surname), 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8');
        $primary = ['kind' => 'primary'];

        if ($given !== '') {
            $primary['given'] = $given;
        }

        if ($surname !== '') {
            $primary['surname'] = $surname;
        }

        // A bare {kind} entry would violate the schema's anyOf(full|surname|given); keep it only when
        // it carries at least one searchable field.
        if (($given !== '') || ($surname !== '')) {
            $entries[] = $primary;
        }

        $birthSurname = ($this->name->birthSurname === null) ? '' : trim($this->name->birthSurname);

        if ($birthSurname !== '') {
            $entries[] = ['kind' => 'birth', 'surname' => mb_substr($birthSurname, 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8')];
        }

        foreach ($this->name->marriedSurnames as $marriedSurname) {
            $trimmedMarried = trim($marriedSurname);

            if ($trimmedMarried !== '') {
                $entries[] = ['kind' => 'married', 'surname' => mb_substr($trimmedMarried, 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8')];
            }
        }

        foreach ($this->name->aliases as $alias) {
            $trimmedAlias = trim($alias);

            if ($trimmedAlias !== '') {
                $entries[] = ['kind' => 'alias', 'full' => mb_substr($trimmedAlias, 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8')];
            }
        }

        if (count($entries) > self::MAX_NAME_ENTRIES) {
            return array_slice($entries, 0, self::MAX_NAME_ENTRIES);
        }

        return $entries;
    }

    /**
     * Serialises the prioritised queries into contract query-hint entries (query + dedupKey required,
     * priority carried through). Each field is truncated to its contract maximum
     * ({@see self::MAX_QUERY_LENGTH}, {@see self::MAX_DEDUP_KEY_LENGTH}); a query whose dedupKey
     * normalises to an empty string is skipped (the schema's `minLength: 1`), and the list is capped
     * to the first {@see self::MAX_QUERY_HINTS} (the queries arrive priority-ordered, so the least
     * important hints are the ones dropped). `queryHints` is optional with no `minItems`, so an empty
     * list stays contract-valid. The queries come from {@see QueryGenerator}, the sole producer, which
     * never emits an empty `query` and always assigns `priority >= 1`; this projection bounds length and
     * count and drops an empty-dedupKey hint, but trusts that producer for the `query`/`priority` minima.
     *
     * @return list<array{query: string, dedupKey: string, priority: int}> The query hints.
     */
    private function queryHints(): array
    {
        $hints = [];

        foreach ($this->queries as $query) {
            $dedupKey = mb_substr($query->dedupKey, 0, self::MAX_DEDUP_KEY_LENGTH, 'UTF-8');

            // A QueryHint.dedupKey must be non-empty; a strip-word-only query normalises to '' — skip
            // it rather than emit a schema-invalid hint.
            if ($dedupKey === '') {
                continue;
            }

            $hints[] = [
                'query'    => mb_substr($query->query, 0, self::MAX_QUERY_LENGTH, 'UTF-8'),
                'dedupKey' => $dedupKey,
                'priority' => $query->priority,
            ];

            if (count($hints) === self::MAX_QUERY_HINTS) {
                break;
            }
        }

        return $hints;
    }
}
