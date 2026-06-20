<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mb_check_encoding;
use function mb_strlen;
use function str_repeat;
use function strlen;

/**
 * Tests the normalizer that folds diacritics, strips titles and tokenises names.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(Normalizer::class)]
final class NormalizerTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string}>
     */
    public static function normalizeCases(): array
    {
        return [
            ['Müller', 'mueller'],
            ['MÜLLER', 'mueller'],
            ['Mueller', 'mueller'],
            ['Weiß', 'weiss'],
            ['Dr. Anna Schmidt', 'anna schmidt'],
            ['Maria geb. Becker', 'maria becker'],
            // Real obituaries write titles/affixes without the trailing dot, so the
            // dotless variants must strip too ("dr schmidt" -> "schmidt").
            ['dr schmidt', 'schmidt'],
            ['maria geb becker', 'maria becker'],
            ['prof mueller', 'mueller'],
            // ...but the dotless marker must never be stripped from inside a word:
            // "andrea" contains "dr" and must stay intact.
            ['andrea', 'andrea'],
            // A title/affix stripped without surrounding spaces must not concatenate its
            // neighbours: "anna geb.becker" yields "anna becker", not "annabecker".
            ['anna geb.becker', 'anna becker'],
            // A DOTTED abbreviation glued straight onto a name (no surrounding space) is safe
            // to strip without a word boundary, because the dot cannot occur mid-name:
            // "dr.schmidt" -> "schmidt", "maria geb.becker" -> "maria becker".
            ['dr.schmidt', 'schmidt'],
            ['maria geb.becker', 'maria becker'],
            // ...but a DOTLESS abbreviation must stay whole-word only: a bare "dr"/"geb" must
            // never be stripped from inside a word ("pedro" contains "dr", "gebhard" "geb").
            ['pedro', 'pedro'],
            ['gebhard', 'gebhard'],
            // A dotless strip-word must never match as a leading substring of the NEXT token:
            // "ingrid" begins with "ing", "inge" begins with "ing", "general" begins with "gen".
            // Whole-word tokenisation keeps these names intact.
            ['maria ingrid', 'maria ingrid'],
            ['inge schmidt', 'inge schmidt'],
            ['ingrid mueller', 'ingrid mueller'],
            ['anna general', 'anna general'],
            ['Anna Dr. Schmidt', 'anna schmidt'],
            ['geb.', ''],
            ['JÉRÔME', 'jerome'],
            ['Jérôme', 'jerome'],
            ['ÅSA', 'asa'],
            ['ÖZGÜR', 'oezguer'],
            ['François', 'francois'],
            ['FRANÇOIS', 'francois'],
            ['Núñez', 'nunez'],
            ['NÚÑEZ', 'nunez'],
            // A multibyte uppercase letter NOT in the fold map (Polish Ł) must still be
            // lowercased: a byte-based strtolower() would leave it uppercase, mb_strtolower
            // correctly yields "ł".
            ['Łukasz', 'łukasz'],
        ];
    }

    /**
     * Verifies that diacritics are folded and titles/affixes are stripped during normalization.
     */
    #[Test]
    #[DataProvider('normalizeCases')]
    public function normalizeFoldsAndStrips(string $input, string $expected): void
    {
        self::assertSame($expected, Normalizer::normalize($input));
    }

    /**
     * Verifies that Müller, Mueller, and Muller all collapse to the same stripped key.
     */
    #[Test]
    public function stripCollapsesDiacriticVariants(): void
    {
        self::assertSame('muller', Normalizer::strip('Müller'));
        self::assertSame('muller', Normalizer::strip('Mueller'));
        self::assertSame('muller', Normalizer::strip('Muller'));
    }

    /**
     * Verifies that uppercase accented characters fold to the same ASCII key as their
     * lowercase counterparts, so case never changes the normalised result.
     */
    #[Test]
    public function normalizeFoldsUppercaseAccentsLikeLowercase(): void
    {
        self::assertSame('jerome', Normalizer::normalize('JÉRÔME'));
        self::assertSame(Normalizer::normalize('jérôme'), Normalizer::normalize('JÉRÔME'));
        self::assertSame('oezguer', Normalizer::normalize('ÖZGÜR'));
        self::assertSame(Normalizer::normalize('özgür'), Normalizer::normalize('ÖZGÜR'));
    }

    /**
     * Verifies that strip reduces uppercase accented characters to their base ASCII letter,
     * yielding the same key regardless of the input letter case.
     */
    #[Test]
    public function stripFoldsUppercaseAccentsToBaseLetter(): void
    {
        self::assertSame('jerome', Normalizer::strip('JÉRÔME'));
        self::assertSame('ozgur', Normalizer::strip('ÖZGÜR'));
        self::assertSame(Normalizer::strip('özgür'), Normalizer::strip('ÖZGÜR'));
        self::assertSame(Normalizer::strip('müller'), Normalizer::strip('MÜLLER'));
    }

    /**
     * Verifies that an oversized untrusted input is truncated before processing.
     */
    #[Test]
    public function normalizeBoundsOversizedInput(): void
    {
        $oversized = str_repeat('a', 5000);
        $result    = Normalizer::normalize($oversized);

        self::assertSame(512, strlen($result));
    }

    /**
     * Verifies that the multibyte-safe length cap never splits a UTF-8 character, even when
     * the equivalent byte-512 boundary would fall inside a two-byte character. The cap is
     * applied to the raw input (before diacritic folding), so a multibyte run that exceeds
     * the character cap must be truncated on a character boundary and stay valid UTF-8.
     */
    #[Test]
    public function normalizeCapNeverSplitsAMultibyteCharacter(): void
    {
        // "ñ" is two bytes; 600 of them is 1200 bytes. A byte-based substr(…, 0, 512)
        // would cut at byte 512, inside the 257th "ñ", producing invalid UTF-8.
        $oversized = str_repeat('ñ', 600);
        $result    = Normalizer::strip($oversized);

        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
        // "ñ" folds to "n"; the input is capped at 512 characters, so at most 512 letters.
        self::assertLessThanOrEqual(512, mb_strlen($result, 'UTF-8'));
    }
}
