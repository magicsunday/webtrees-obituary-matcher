<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\FinderPortal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Unit tests for the per-field defensive narrowing of a single untrusted capabilities portal entry.
 * Each test pins one drop branch of {@see FinderPortal::tryFromArray()} — a non-array entry or an
 * invalid identifier collapses the whole entry to null, while an oversize/wrong-typed name, country or
 * region degrades that one field (the portal survives) so a hand-crafted capabilities document never
 * poisons the admin readout that consumes it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderPortal::class)]
final class FinderPortalTest extends TestCase
{
    /** A non-array entry cannot describe a portal, so the whole entry collapses to null. */
    #[Test]
    public function aNonArrayEntryIsNull(): void
    {
        self::assertNull(FinderPortal::tryFromArray('not-an-array'));
        self::assertNull(FinderPortal::tryFromArray(42));
        self::assertNull(FinderPortal::tryFromArray(null));
    }

    /**
     * An id failing the identifier pattern (here a space) drops the WHOLE entry — the id is the one
     * required field.
     */
    #[Test]
    public function anInvalidIdDropsTheWholeEntry(): void
    {
        self::assertNull(FinderPortal::tryFromArray(['id' => 'NOT VALID']));
        self::assertNull(FinderPortal::tryFromArray(['id' => 123]));
    }

    /** A name longer than 200 characters is dropped to null, but the portal itself survives. */
    #[Test]
    public function anOversizeNameIsDroppedButThePortalSurvives(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'name' => str_repeat('a', 201)]);

        self::assertNotNull($portal);
        self::assertSame('p', $portal->id);
        self::assertNull($portal->name);
    }

    /** A non-string name is dropped to null while the portal survives. */
    #[Test]
    public function aNonStringNameIsNull(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'name' => 123]);

        self::assertNotNull($portal);
        self::assertNull($portal->name);
    }

    /** A well-formed name within the length cap is kept verbatim. */
    #[Test]
    public function aValidNameIsKept(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'name' => 'Portal P']);

        self::assertNotNull($portal);
        self::assertSame('Portal P', $portal->name);
    }

    /**
     * A country failing the ISO-3166 alpha-2 upper-case pattern is dropped to null while the portal
     * survives.
     *
     * @param mixed $country The invalid country value.
     */
    #[Test]
    #[DataProvider('invalidCountryProvider')]
    public function anInvalidCountryIsNull(mixed $country): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'country' => $country]);

        self::assertNotNull($portal);
        self::assertNull($portal->country);
    }

    /**
     * The invalid-country cases: lower-case, three-letter and non-string values all fail the
     * `^[A-Z]{2}$` shape.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidCountryProvider(): iterable
    {
        yield 'lower-case' => ['de'];
        yield 'three-letter' => ['DEU'];
        yield 'non-string' => [123];
    }

    /** A valid ISO-3166 alpha-2 country is kept verbatim. */
    #[Test]
    public function aValidCountryIsKept(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'country' => 'DE']);

        self::assertNotNull($portal);
        self::assertSame('DE', $portal->country);
    }

    /**
     * A mixed regions list keeps the valid string entries and drops a non-string and an oversize string,
     * re-indexing the survivors into a dense list.
     */
    #[Test]
    public function regionsKeepValidStringsAndDropTheRest(): void
    {
        $portal = FinderPortal::tryFromArray([
            'id'      => 'p',
            'regions' => ['Bayern', str_repeat('x', 201), 99, 'Hessen'],
        ]);

        self::assertNotNull($portal);
        self::assertSame(['Bayern', 'Hessen'], $portal->regions);
    }

    /** A non-array regions value yields the empty list (not null, and not a fatal). */
    #[Test]
    public function aNonArrayRegionsIsEmpty(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'regions' => 'Bayern']);

        self::assertNotNull($portal);
        self::assertSame([], $portal->regions);
    }

    /**
     * The id validator is anchored with `/D`, so a trailing newline is NOT swallowed by `$`: an
     * otherwise-pattern-matching id carrying a `\n` fails the anchor and drops the whole entry.
     */
    #[Test]
    public function aTrailingNewlineInTheIdDropsTheWholeEntry(): void
    {
        self::assertNull(FinderPortal::tryFromArray(['id' => "p\n"]));
    }

    /**
     * The country validator is anchored with `/D`, so a trailing newline is NOT swallowed by `$`: a
     * `DE\n` value fails the anchor and is dropped to null while the portal survives.
     */
    #[Test]
    public function aTrailingNewlineInTheCountryIsNull(): void
    {
        $portal = FinderPortal::tryFromArray(['id' => 'p', 'country' => "DE\n"]);

        self::assertNotNull($portal);
        self::assertNull($portal->country);
    }
}
