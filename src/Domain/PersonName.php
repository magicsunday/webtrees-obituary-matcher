<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use function implode;

/**
 * A name decomposed into roles for role-based comparison.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PersonName
{
    /**
     * Constructor.
     *
     * @param list<string> $givenNames      Given names in order.
     * @param string|null  $callName        Rufname (the everyday given name), if known.
     * @param string       $surname         Primary/display surname from the source mapping.
     * @param string|null  $birthSurname    Explicit birth surname (Geburtsname) when distinct.
     * @param list<string> $marriedSurnames Known married surnames (Ehename).
     * @param list<string> $aliases         Other recorded name forms.
     */
    public function __construct(
        public array $givenNames,
        public ?string $callName,
        public string $surname,
        public ?string $birthSurname,
        public array $marriedSurnames = [],
        public array $aliases = [],
    ) {
    }

    /**
     * Whether the name carries at least one non-empty, searchable token across any role.
     *
     * MUST stay in lockstep with {@see \MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest}'s
     * `nameEntries()` projection: the request builder
     * relies on this predicate to exclude unsearchable candidates BEFORE projection, so the emitted
     * `names` array can never be empty (the schema's `minItems: 1`). The non-empty inclusion logic
     * here mirrors that method's exactly — a given-name token, the surname, an explicit birth
     * surname, any married surname or any alias.
     *
     * @return bool True when at least one name role holds a non-empty string; false for the wholly
     *              empty name (a webtrees `@P.N.`/`@N.N.` placeholder person, unsearchable).
     */
    public function hasSearchableName(): bool
    {
        if (implode('', $this->givenNames) !== '') {
            return true;
        }

        if ($this->surname !== '') {
            return true;
        }

        if (($this->birthSurname !== null) && ($this->birthSurname !== '')) {
            return true;
        }

        foreach ($this->marriedSurnames as $marriedSurname) {
            if ($marriedSurname !== '') {
                return true;
            }
        }

        foreach ($this->aliases as $alias) {
            if ($alias !== '') {
                return true;
            }
        }

        return false;
    }
}
