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

use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_match;
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
     * The maximum number of excluded hosts emitted per candidate (the schema's `maxItems`). The producer
     * caps its (deduplicated, sorted) list to this bound so a candidate with more open-match hosts still
     * yields a schema-valid body — the hint is advisory, so dropping the tail past the cap only forgoes a
     * few "don't re-return" hints, never a match.
     */
    private const int MAX_EXCLUDED_HOSTS = 50;

    /**
     * The PCRE predicate an excluded host must match to be emitted. It is a deliberately STRICTER
     * (Unicode-aware, `/u`) twin of the schema's `excludedHosts.items.pattern`. The schema pattern is
     * ECMA-262 and relies on `\s`, whose meaning varies by validator (a byte-mode PCRE `\s` is ASCII-only;
     * ECMA and Python `\s` match different Unicode whitespace sets). To keep the producer output a subset
     * of what EVERY conformant validator accepts — so one normaliser-emitted host can never make the whole
     * job request schema-invalid — this rejects the UNION of all those sets by class rather than
     * enumerating codepoints: `\p{Z}` (every Unicode separator/whitespace) and `\p{C}` (every control and
     * format character, incl. NEL, the BOM and the C0 information separators) alongside the ASCII `\s`,
     * the URL-structural characters and uppercase. What survives is only graphical, non-structural hosts
     * (letters, digits, dots, hyphens — ASCII and IDN), which every validator admits. The stricter-than-
     * schema direction is safe (it only forgoes an advisory hint for a malformed host); a contract test
     * pins the length/array constraints and the subset relationship is verified behaviourally.
     */
    private const string EXCLUDED_HOST_PATTERN = '~^[^\s\p{Z}\p{C}/:?#\[\]@\\\\*%A-Z]+$~u';

    /**
     * The maximum length (in Unicode codepoints) of an excluded host — the schema's `maxLength` (the
     * 253-octet DNS name ceiling). The normaliser does not bound host length, so a host longer than this
     * is filtered out rather than being allowed to make the whole request schema-invalid.
     */
    private const int EXCLUDED_HOST_MAX_LENGTH = 253;

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
     *                                            match on; an advisory finder hint (also used enqueue-side).
     *                                            {@see self::toArray()} emits the contract-conforming, capped
     *                                            subset onto the wire when non-empty.
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
     * Emits the person reference, the projected `names`, the pre-built `queryHints` and — only when the
     * candidate actually has open matches to skip — the `excludedHosts` hint. An empty exclusion list is
     * omitted so a candidate with no open matches produces a minimal wire body.
     *
     * @return array{
     *   personId: string,
     *   names: list<array{kind?: string, full?: string, given?: string, surname?: string}>,
     *   queryHints: list<array{query: string, dedupKey: string, priority: int}>,
     *   excludedHosts?: list<string>
     * }
     */
    public function toArray(): array
    {
        $candidate = [
            'personId'   => $this->personId,
            'names'      => $this->nameEntries(),
            'queryHints' => $this->queryHints(),
        ];

        // The excludedHosts hint is advisory (the matcher de-dupes on ingest regardless), so an empty
        // list carries no information — omit it to keep the wire body minimal, and only emit it when the
        // candidate has at least one host to skip. The empty case is the default and most common, so guard
        // it first to skip the whole pipeline. Otherwise enforce every contract constraint at this boundary
        // so the emitted subset is always schema-valid regardless of the (public, arbitrary) input: filter
        // to fully conforming hosts (the host normaliser's output domain is broader than the contract — a
        // bracketed IPv6 literal or an over-long host — and one non-conforming host must not invalidate the
        // whole request), deduplicate (the schema requires uniqueItems), then cap to the contract maximum.
        if ($this->excludedHosts !== []) {
            // Deduplicate BEFORE filtering so the regex/length checks run once per distinct host, not once
            // per occurrence (conformsToContract is idempotent, so the result is identical either way).
            $hosts = array_values(array_filter(array_unique($this->excludedHosts), $this->conformsToContract(...)));

            if ($hosts !== []) {
                $candidate['excludedHosts'] = array_slice($hosts, 0, self::MAX_EXCLUDED_HOSTS);
            }
        }

        return $candidate;
    }

    /**
     * Whether a host satisfies EVERY per-item constraint of the schema's `excludedHosts` array — the
     * length bounds AND the character pattern — so the producer's emitted list is a subset of what the
     * contract accepts and no single normaliser-emitted host can invalidate the whole request. Length is
     * measured in Unicode codepoints to match JSON-Schema `minLength`/`maxLength` semantics.
     *
     * @param string $host The candidate host to test.
     *
     * @return bool True when the host conforms to every excludedHosts item constraint.
     */
    private function conformsToContract(string $host): bool
    {
        // Pattern first: the `/u` predicate also rejects invalid UTF-8, so mb_strlen only ever measures a
        // well-formed string, and a host failing the (cheap) character check skips the multibyte length
        // calculation entirely. The pattern's `+` quantifier already enforces the schema's minLength of 1
        // (a match is never empty), so only the upper length bound remains to check here.
        if (preg_match(self::EXCLUDED_HOST_PATTERN, $host) !== 1) {
            return false;
        }

        return mb_strlen($host, 'UTF-8') <= self::EXCLUDED_HOST_MAX_LENGTH;
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

        $given   = $this->boundedNameField(implode(' ', $cleanGivenNames));
        $surname = $this->boundedNameField($this->name->surname);
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

        $birthSurname = $this->boundedNameField($this->name->birthSurname ?? '');

        if ($birthSurname !== '') {
            $entries[] = ['kind' => 'birth', 'surname' => $birthSurname];
        }

        foreach ($this->name->marriedSurnames as $marriedSurname) {
            $married = $this->boundedNameField($marriedSurname);

            if ($married !== '') {
                $entries[] = ['kind' => 'married', 'surname' => $married];
            }
        }

        foreach ($this->name->aliases as $alias) {
            $boundedAlias = $this->boundedNameField($alias);

            if ($boundedAlias !== '') {
                $entries[] = ['kind' => 'alias', 'full' => $boundedAlias];
            }
        }

        if (count($entries) > self::MAX_NAME_ENTRIES) {
            return array_slice($entries, 0, self::MAX_NAME_ENTRIES);
        }

        return $entries;
    }

    /**
     * Serialises the prioritised queries into contract query-hint entries. Each of `query`/`dedupKey` is
     * trimmed and truncated to its contract maximum ({@see self::MAX_QUERY_LENGTH},
     * {@see self::MAX_DEDUP_KEY_LENGTH}); a hint whose `query` or `dedupKey` ends up blank is skipped (the
     * schema's `minLength: 1`), `priority` is clamped to the schema minimum of 1, and the list is capped
     * to the first {@see self::MAX_QUERY_HINTS} (the queries arrive priority-ordered, so the least
     * important hints are the ones dropped). `queryHints` is optional with no `minItems`, so a blank list
     * stays contract-valid — the projection is therefore total: it emits a schema-valid hint list for any
     * {@see CandidateQuery} contents (production queries come from {@see QueryGenerator}).
     *
     * @return list<array{query: string, dedupKey: string, priority: int}> The query hints.
     */
    private function queryHints(): array
    {
        $hints = [];

        foreach ($this->queries as $query) {
            $queryText = trim(mb_substr($query->query, 0, self::MAX_QUERY_LENGTH, 'UTF-8'));
            $dedupKey  = trim(mb_substr($query->dedupKey, 0, self::MAX_DEDUP_KEY_LENGTH, 'UTF-8'));

            // A QueryHint requires a non-empty `query` and `dedupKey` (the schema's `minLength: 1`); a
            // strip-word-only query normalises to an empty dedupKey. Skip such a hint rather than emit a
            // schema-invalid one — `queryHints` is optional, so dropping it stays contract-valid.
            if ($queryText === '') {
                continue;
            }

            if ($dedupKey === '') {
                continue;
            }

            $hints[] = [
                'query'    => $queryText,
                'dedupKey' => $dedupKey,
                // priority has a schema minimum of 1 (absent defaults to 1); clamp a non-positive value.
                'priority' => max(1, $query->priority),
            ];

            if (count($hints) === self::MAX_QUERY_HINTS) {
                break;
            }
        }

        return $hints;
    }

    /**
     * Trims, truncates to the contract name-field maximum ({@see self::MAX_NAME_FIELD_LENGTH}) and trims
     * again, so neither a padded source value nor a truncation landing on a space leaves a leading or
     * trailing blank on the wire. UTF-8 keeps the cut on a character boundary.
     *
     * @param string $value The raw name token.
     *
     * @return string The trimmed, length-bounded name value (possibly empty).
     */
    private function boundedNameField(string $value): string
    {
        return trim(mb_substr(trim($value), 0, self::MAX_NAME_FIELD_LENGTH, 'UTF-8'));
    }
}
