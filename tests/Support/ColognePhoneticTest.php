<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\ColognePhonetic;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Cologne phonetics (Kölner Phonetik) encoder that maps German names to phonetic codes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ColognePhonetic::class)]
#[UsesClass(Normalizer::class)]
final class ColognePhoneticTest extends TestCase
{
    /**
     * Verifies that a common German name is encoded to the correct Cologne code.
     */
    #[Test]
    public function smokeCodeForMueller(): void
    {
        self::assertSame('657', (new ColognePhonetic())->encode('Müller'));
    }

    /**
     * Verifies that common spelling variants of the same name produce identical codes.
     */
    #[Test]
    public function variantsShareACode(): void
    {
        $encoder = new ColognePhonetic();
        self::assertSame('67', $encoder->encode('Meyer'));
        self::assertSame('67', $encoder->encode('Maier'));
        self::assertSame('67', $encoder->encode('Mayer'));
        self::assertSame('862', $encoder->encode('Schmidt'));
        self::assertSame('862', $encoder->encode('Schmitt'));
    }

    /**
     * Verifies that phonetically distinct names produce different codes.
     */
    #[Test]
    public function differentNamesDifferentCodes(): void
    {
        $encoder = new ColognePhonetic();
        self::assertNotSame($encoder->encode('Mueller'), $encoder->encode('Schmidt'));
    }

    /**
     * Verifies that an empty input encodes to an empty string without error.
     */
    #[Test]
    public function emptyInputEncodesToEmptyString(): void
    {
        self::assertSame('', (new ColognePhonetic())->encode(''));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function vowelCodedJAndYCases(): array
    {
        return [
            // J/Y are coded as vowels ('0'), so they are no longer silently dropped.
            // Between two identically-coded consonants the inserted '0' prevents the
            // adjacent-duplicate collapse, which is the observable spec difference.
            ['rjr', '77'],
            ['lyl', '55'],
            // A leading-area J-name encodes to the spec code rather than dropping the J.
            ['Johann', '06'],
            ['Yohann', '06'],
        ];
    }

    /**
     * Verifies that J and Y are coded as vowels ('0') per the Cologne phonetics spec,
     * rather than being dropped like a default consonant.
     */
    #[Test]
    #[DataProvider('vowelCodedJAndYCases')]
    public function codesJAndYAsVowels(string $input, string $expected): void
    {
        self::assertSame($expected, (new ColognePhonetic())->encode($input));
    }

    /**
     * Verifies that a J-name encodes to a stable, non-empty code and that the J/Y
     * vowel equivalence holds (Johann and Yohann share a code).
     */
    #[Test]
    public function jAndYAreEquivalentVowels(): void
    {
        $encoder = new ColognePhonetic();

        self::assertNotSame('', $encoder->encode('Johann'));
        self::assertSame($encoder->encode('Johann'), $encoder->encode('Yohann'));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function boundaryConditionCases(): array
    {
        return [
            // Terminal d: next='' must NOT trigger the csz-branch (would yield '8' without the guard)
            ['Bad', '12'],
            // Terminal t: next='' must NOT trigger the csz-branch (would yield '8' without the guard)
            ['Rat', '72'],
        ];
    }

    /**
     * Verifies that a word-terminal d or t is coded as '2', not '8'.
     *
     * Without the empty-string boundary guard in codeFor(), str_contains('csz', '')
     * returns true, which would mis-code terminal d/t as '8'.
     */
    #[Test]
    #[DataProvider('boundaryConditionCases')]
    public function terminalConsonantIsNotMiscoded(string $input, string $expected): void
    {
        self::assertSame($expected, (new ColognePhonetic())->encode($input));
    }
}
