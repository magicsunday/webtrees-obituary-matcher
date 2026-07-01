<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\PersonName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see PersonName::hasSearchableName()}: the predicate the enqueue producer uses to
 * exclude an unsearchable candidate BEFORE it enters the finder request, so the projected `names`
 * array can never be empty (the #56 contract's `minItems: 1`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PersonName::class)]
final class PersonNameTest extends TestCase
{
    /**
     * The wholly empty name — a webtrees `@P.N.`/`@N.N.` placeholder person — is not searchable, and
     * neither is a name whose only tokens are empty strings.
     *
     * @param PersonName $name The name shape under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsearchableNameProvider')]
    public function hasSearchableNameIsFalseForAnEmptyName(PersonName $name): void
    {
        self::assertFalse($name->hasSearchableName());
    }

    /**
     * A name carrying a non-empty token in ANY single role is searchable.
     *
     * @param PersonName $name The name shape under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('searchableNameProvider')]
    public function hasSearchableNameIsTrueForEachSingleFieldShape(PersonName $name): void
    {
        self::assertTrue($name->hasSearchableName());
    }

    /**
     * The unsearchable name shapes.
     *
     * @return array<string, array{PersonName}> The provider rows.
     */
    public static function unsearchableNameProvider(): array
    {
        return [
            'all empty'               => [new PersonName([], null, '', null, [], [])],
            'empty given token'       => [new PersonName([''], null, '', null, [], [])],
            'empty married token'     => [new PersonName([], null, '', null, [''], [])],
            'empty alias token'       => [new PersonName([], null, '', null, [], [''])],
            'empty birth surname'     => [new PersonName([], null, '', '', [], [])],
            'whitespace-only given'   => [new PersonName(['   '], null, '', null, [], [])],
            'whitespace-only surname' => [new PersonName([], null, "\t ", null, [], [])],
            'whitespace-only alias'   => [new PersonName([], null, '', null, [], [' '])],
        ];
    }

    /**
     * The single-field-present searchable name shapes.
     *
     * @return array<string, array{PersonName}> The provider rows.
     */
    public static function searchableNameProvider(): array
    {
        return [
            'given only'         => [new PersonName(['Erika'], null, '', null, [], [])],
            'padded given'       => [new PersonName([' Erika '], null, '', null, [], [])],
            'surname only'       => [new PersonName([], null, 'Mustermann', null, [], [])],
            'birth surname only' => [new PersonName([], null, '', 'Mueller', [], [])],
            'married only'       => [new PersonName([], null, '', null, ['Schmidt'], [])],
            'alias only'         => [new PersonName([], null, '', null, [], ['Erika Musterfrau'])],
        ];
    }
}
