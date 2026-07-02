<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\ControlChars;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared ASCII control-character predicate: the C0 range (U+0000–U+001F) and DEL (U+007F)
 * are detected, while printable characters — including the boundary neighbours SPACE (0x20) and
 * TILDE (0x7E) — are not.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ControlChars::class)]
final class ControlCharsTest extends TestCase
{
    /**
     * Provides subjects paired with the expected control-character verdict, covering both boundaries of
     * the C0 range, DEL, common whitespace controls (which ARE C0), and the printable neighbours that
     * must NOT trip the guard.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function subjects(): array
    {
        return [
            'clean printable url'      => ['https://trauer.example/path', false],
            'empty string'             => ['', false],
            'unicode letters'          => ['Waldfriedhof Grüße', false],
            'space (0x20) not control' => ['a b', false],
            'tilde (0x7E) not control' => ["a\x7Eb", false],
            'NUL (0x00 low bound)'     => ["a\x00b", true],
            'US (0x1F high bound)'     => ["a\x1Fb", true],
            'DEL (0x7F)'               => ["a\x7Fb", true],
            'tab (0x09)'               => ["a\tb", true],
            'newline (0x0A)'           => ["a\nb", true],
            'carriage return (0x0D)'   => ["a\rb", true],
        ];
    }

    /**
     * A subject containing any C0 control or DEL is reported; a purely printable subject is not.
     *
     * @param string $subject  The string under test.
     * @param bool   $expected The expected verdict.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('subjects')]
    public function containsDetectsAsciiControlCharacters(string $subject, bool $expected): void
    {
        self::assertSame($expected, ControlChars::contains($subject));
    }
}
