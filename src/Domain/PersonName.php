<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

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
}
