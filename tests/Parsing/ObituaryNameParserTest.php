<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Parsing;

use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function mb_check_encoding;
use function str_repeat;

/**
 * Tests the ObituaryNameParser: given names, surnames, birth names and widow forms.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryNameParser::class)]
#[UsesClass(PersonName::class)]
final class ObituaryNameParserTest extends TestCase
{
    /**
     * A plain "given surname" string splits into given names and surname.
     */
    #[Test]
    public function parsesGivenNamesAndSurname(): void
    {
        $name = ObituaryNameParser::parse('Erika Mustermann');
        self::assertSame(['Erika'], $name->givenNames);
        self::assertSame('Mustermann', $name->surname);
        self::assertNull($name->birthSurname);
    }

    /**
     * A "geb." marker captures the birth surname separately from the current surname.
     */
    #[Test]
    public function parsesBornSurname(): void
    {
        $name = ObituaryNameParser::parse('Maria Schmidt geb. Becker');
        self::assertSame(['Maria'], $name->givenNames);
        self::assertSame('Schmidt', $name->surname);
        self::assertSame('Becker', $name->birthSurname);
    }

    /**
     * Multiple given names are all retained alongside surname and birth surname.
     */
    #[Test]
    public function keepsMultipleGivenNames(): void
    {
        $name = ObituaryNameParser::parse('Anna Maria Elisabeth Schmitz geborene Mueller');
        self::assertSame(['Anna', 'Maria', 'Elisabeth'], $name->givenNames);
        self::assertSame('Schmitz', $name->surname);
        self::assertSame('Mueller', $name->birthSurname);
    }

    /**
     * The widowed ("verw.") form is dropped while the current and birth surnames remain.
     */
    #[Test]
    public function dropsWidowedFormButKeepsCurrentSurnameAndBorn(): void
    {
        $name = ObituaryNameParser::parse('Maria Schmidt verw. Becker geb. Mueller');
        self::assertSame('Schmidt', $name->surname);
        self::assertSame('Mueller', $name->birthSurname);
    }

    /**
     * Consecutive markers do not capture each other: the real name following the
     * repeated marker becomes the birth surname.
     */
    #[Test]
    public function consecutiveMarkersDoNotCaptureEachOther(): void
    {
        $name = ObituaryNameParser::parse('Maria geb. geb. Becker');
        self::assertSame('Becker', $name->birthSurname);
    }

    /**
     * A trailing marker with no following token captures nothing, leaving the birth
     * surname unset while the rest of the name is handled sanely.
     */
    #[Test]
    public function trailingMarkerCapturesNothing(): void
    {
        $name = ObituaryNameParser::parse('Maria geb.');
        self::assertNull($name->birthSurname);
        self::assertSame('Maria', $name->surname);
        self::assertSame([], $name->givenNames);
    }

    /**
     * A born marker is recognised case-insensitively even when its uppercase form carries a
     * multibyte letter: "GEBÜRTIGE" must lowercase to the "gebürtige" marker via mb_strtolower,
     * which a byte-based strtolower() cannot do for the "Ü".
     */
    #[Test]
    public function recognisesAccentedBornMarkerRegardlessOfCase(): void
    {
        $name = ObituaryNameParser::parse('Maria Schmidt GEBÜRTIGE Müller');
        self::assertSame(['Maria'], $name->givenNames);
        self::assertSame('Schmidt', $name->surname);
        self::assertSame('Müller', $name->birthSurname);
    }

    /**
     * A multi-KB untrusted input is bounded: the parsed name never exceeds the token cap.
     */
    #[Test]
    public function boundsOversizedUntrustedInput(): void
    {
        // 2000 single-character tokens, well over the byte and token caps.
        $oversized = str_repeat('a ', 2000);

        $name = ObituaryNameParser::parse($oversized);

        // Given names plus the surname token must stay within the 65-token cap.
        self::assertLessThanOrEqual(65, count($name->givenNames) + 1);
    }

    /**
     * Verifies that the multibyte-safe raw-length cap never splits a UTF-8 character: an
     * oversized run of two-byte characters whose byte-512 boundary falls inside a character
     * must still produce well-formed UTF-8 tokens.
     */
    #[Test]
    public function boundsOversizedMultibyteInputWithoutSplittingACharacter(): void
    {
        // "ä" is two bytes; 600 of them is 1200 bytes. A byte-based cut at 512 would land
        // inside a character. mb_substr keeps the truncation on a character boundary.
        $oversized = str_repeat('ä', 600);

        $name      = ObituaryNameParser::parse($oversized);
        $assembled = implode(' ', [...$name->givenNames, $name->surname]);

        self::assertTrue(mb_check_encoding($assembled, 'UTF-8'));
        self::assertTrue(mb_check_encoding($name->surname, 'UTF-8'));
    }
}
