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
}
