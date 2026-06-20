<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\RufnameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the parser that extracts the German call name (_RUFNAME) from a GEDCOM record.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RufnameParser::class)]
final class RufnameParserTest extends TestCase
{
    /**
     * Provides GEDCOM fragments paired with the expected call name (or null when absent).
     *
     * @return list<array{0:string,1:?string}>
     */
    public static function cases(): array
    {
        return [
            ["0 @I1@ INDI\n1 NAME Karl Heinz /Muster/\n2 _RUFNAME Heinz\n", 'Heinz'],
            ["1 _RUFNAME  Heinz  \n", 'Heinz'],                       // surrounding whitespace
            ["1 _RUFNAME Heinz\n1 _RUFNAME Karl\n", 'Heinz'],         // first wins
            ["0 @I1@ INDI\n1 NAME Otto /Vorbild/\n", null],           // absent
        ];
    }

    /**
     * Verifies that the call name is extracted from the _RUFNAME tag, or null when absent.
     */
    #[Test]
    #[DataProvider('cases')]
    public function parsesRufname(string $gedcom, ?string $expected): void
    {
        self::assertSame($expected, RufnameParser::parse($gedcom));
    }
}
