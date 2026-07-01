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
     * married and alias — dropping any empty form and keeping at most {@see self::MAX_NAME_ENTRIES}
     * (primary first). A candidate always carries a surname or a given name, so the list is non-empty;
     * the schema's `minItems: 1` and the producer contract test pin that at the boundary.
     *
     * @return list<array{kind?: string, full?: string, given?: string, surname?: string}> The projected name entries.
     */
    private function nameEntries(): array
    {
        $entries = [];

        $given   = implode(' ', $this->name->givenNames);
        $primary = ['kind' => 'primary'];

        if ($given !== '') {
            $primary['given'] = $given;
        }

        if ($this->name->surname !== '') {
            $primary['surname'] = $this->name->surname;
        }

        // A bare {kind} entry would violate the schema's anyOf(full|surname|given); keep it only when
        // it carries at least one searchable field.
        if (($given !== '') || ($this->name->surname !== '')) {
            $entries[] = $primary;
        }

        if (($this->name->birthSurname !== null) && ($this->name->birthSurname !== '')) {
            $entries[] = ['kind' => 'birth', 'surname' => $this->name->birthSurname];
        }

        foreach ($this->name->marriedSurnames as $marriedSurname) {
            if ($marriedSurname !== '') {
                $entries[] = ['kind' => 'married', 'surname' => $marriedSurname];
            }
        }

        foreach ($this->name->aliases as $alias) {
            if ($alias !== '') {
                $entries[] = ['kind' => 'alias', 'full' => $alias];
            }
        }

        if (count($entries) > self::MAX_NAME_ENTRIES) {
            return array_slice($entries, 0, self::MAX_NAME_ENTRIES);
        }

        return $entries;
    }

    /**
     * Serialises the prioritised queries into contract query-hint entries (query + dedupKey required,
     * priority carried through).
     *
     * @return list<array{query: string, dedupKey: string, priority: int}> The query hints.
     */
    private function queryHints(): array
    {
        $hints = [];

        foreach ($this->queries as $query) {
            $hints[] = [
                'query'    => $query->query,
                'dedupKey' => $query->dedupKey,
                'priority' => $query->priority,
            ];
        }

        return $hints;
    }
}
