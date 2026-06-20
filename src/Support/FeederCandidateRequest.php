<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

/**
 * The feeder-request portion describing a single candidate and its prioritised queries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FeederCandidateRequest
{
    /**
     * Constructor.
     *
     * @param string               $personId The candidate's stable identifier (e.g. the GEDCOM xref).
     * @param list<CandidateQuery> $queries  The prioritised, deduplicated plain-text queries.
     */
    public function __construct(
        public string $personId,
        public array $queries,
    ) {
    }

    /**
     * Serialises the candidate into a plain, JSON-ready array.
     *
     * @return array{personId: string, queries: list<array{query: string, priority: int, dedupKey: string}>}
     */
    public function toArray(): array
    {
        $queries = [];

        foreach ($this->queries as $query) {
            $queries[] = [
                'query'    => $query->query,
                'priority' => $query->priority,
                'dedupKey' => $query->dedupKey,
            ];
        }

        return [
            'personId' => $this->personId,
            'queries'  => $queries,
        ];
    }
}
