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
     * married and alias — dropping any empty form, truncating each field to the contract maximum
     * ({@see self::MAX_NAME_FIELD_LENGTH}) and keeping at most {@see self::MAX_NAME_ENTRIES}
     * (primary first).
     *
     * MUST stay in lockstep with {@see PersonName::hasSearchableName()}: the enqueue producer excludes
     * every candidate for which that predicate is false, so a candidate that reaches here always
     * carries at least one non-empty token and the list is non-empty (the schema's `minItems: 1`). The
     * producer contract test pins that invariant at the boundary.
     *
     * @return list<array{kind?: string, full?: string, given?: string, surname?: string}> The projected name entries.
     */
    private function nameEntries(): array
    {
        $entries = [];

        $given   = mb_substr(implode(' ', $this->name->givenNames), 0, self::MAX_NAME_FIELD_LENGTH);
        $surname = mb_substr($this->name->surname, 0, self::MAX_NAME_FIELD_LENGTH);
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

        if (($this->name->birthSurname !== null) && ($this->name->birthSurname !== '')) {
            $entries[] = ['kind' => 'birth', 'surname' => mb_substr($this->name->birthSurname, 0, self::MAX_NAME_FIELD_LENGTH)];
        }

        foreach ($this->name->marriedSurnames as $marriedSurname) {
            if ($marriedSurname !== '') {
                $entries[] = ['kind' => 'married', 'surname' => mb_substr($marriedSurname, 0, self::MAX_NAME_FIELD_LENGTH)];
            }
        }

        foreach ($this->name->aliases as $alias) {
            if ($alias !== '') {
                $entries[] = ['kind' => 'alias', 'full' => mb_substr($alias, 0, self::MAX_NAME_FIELD_LENGTH)];
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
     * list stays contract-valid.
     *
     * @return list<array{query: string, dedupKey: string, priority: int}> The query hints.
     */
    private function queryHints(): array
    {
        $hints = [];

        foreach ($this->queries as $query) {
            $dedupKey = mb_substr($query->dedupKey, 0, self::MAX_DEDUP_KEY_LENGTH);

            // A QueryHint.dedupKey must be non-empty; a strip-word-only query normalises to '' — skip
            // it rather than emit a schema-invalid hint.
            if ($dedupKey === '') {
                continue;
            }

            $hints[] = [
                'query'    => mb_substr($query->query, 0, self::MAX_QUERY_LENGTH),
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
