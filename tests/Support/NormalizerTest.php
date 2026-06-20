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
            ['Anna Dr. Schmidt', 'anna schmidt'],
            ['geb.', ''],
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
     * Verifies that an oversized untrusted input is truncated before processing.
     */
    #[Test]
    public function normalizeBoundsOversizedInput(): void
    {
        $oversized = str_repeat('a', 5000);
        $result    = Normalizer::normalize($oversized);

        self::assertSame(512, strlen($result));
    }
}
