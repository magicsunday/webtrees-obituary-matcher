<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\PlaceHierarchy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the comma-segment splitter for GEDCOM place hierarchies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PlaceHierarchy::class)]
final class PlaceHierarchyTest extends TestCase
{
    /**
     * Provides raw place strings and their expected RAW (un-normalised) segments.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function segmentProvider(): iterable
    {
        yield 'three-part hierarchy' => [
            'Bad Hersfeld, Hessen, Deutschland',
            ['Bad Hersfeld', 'Hessen', 'Deutschland'],
        ];

        yield 'four-part hierarchy' => [
            'Connewitz, Leipzig, Sachsen, Deutschland',
            ['Connewitz', 'Leipzig', 'Sachsen', 'Deutschland'],
        ];

        yield 'single segment' => [
            'Güldengossa',
            ['Güldengossa'],
        ];

        yield 'whitespace and empty parts are dropped' => [
            ' A ,, B ',
            ['A', 'B'],
        ];

        yield 'empty string yields no segments' => [
            '',
            [],
        ];

        yield 'whitespace-only string yields no segments' => [
            '  ,  ',
            [],
        ];
    }

    /**
     * The splitter trims each comma-segment, drops empty parts and keeps the raw casing/diacritics.
     *
     * @param string       $place    The raw place string.
     * @param list<string> $expected The expected raw segments.
     */
    #[Test]
    #[DataProvider('segmentProvider')]
    public function segmentsSplitsTrimsAndDropsEmptyParts(string $place, array $expected): void
    {
        self::assertSame($expected, PlaceHierarchy::segments($place));
    }
}
