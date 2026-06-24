<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\PayloadReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Behavioural tests for the shared payload-narrowing seam. Each row pins a distinct branch of the
 * defensive narrowing the seam performs over the untrusted on-disk payload: a present/absent key, a
 * type-mismatch falling back to the default, and the two-level nested-string narrowing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PayloadReader::class)]
final class PayloadReaderTest extends TestCase
{
    /**
     * A present key yields its value; an absent key yields null.
     *
     * @return void
     */
    #[Test]
    public function readReturnsValueForPresentKeyAndNullForAbsentKey(): void
    {
        $source = ['present' => 'value', 'nullValue' => null];

        self::assertSame('value', PayloadReader::read($source, 'present'));
        self::assertNull(PayloadReader::read($source, 'absent'));
    }

    /**
     * {@see PayloadReader::asString()} returns a string verbatim and falls back to the default for
     * every non-string shape.
     *
     * @param mixed  $value    The raw value read from the payload.
     * @param string $expected The expected narrowed string.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('asStringCases')]
    public function asStringNarrowsToStringOrDefault(mixed $value, string $expected): void
    {
        self::assertSame($expected, PayloadReader::asString($value, 'fallback'));
    }

    /**
     * Provides the asString narrowing cases.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function asStringCases(): array
    {
        return [
            'string passes through' => ['kept', 'kept'],
            'empty string passes'   => ['', ''],
            'null falls back'       => [null, 'fallback'],
            'int falls back'        => [42, 'fallback'],
            'array falls back'      => [['a'], 'fallback'],
            'bool falls back'       => [true, 'fallback'],
            'stringable falls back' => [new class implements Stringable {
                public function __toString(): string
                {
                    return 'coerced';
                }
            }, 'fallback'],
        ];
    }

    /**
     * {@see PayloadReader::asInt()} returns an int verbatim and falls back to the default for every
     * non-int shape — including a float, since {@see is_int()} rejects it.
     *
     * @param mixed $value    The raw value read from the payload.
     * @param int   $expected The expected narrowed int.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('asIntCases')]
    public function asIntNarrowsToIntOrDefault(mixed $value, int $expected): void
    {
        self::assertSame($expected, PayloadReader::asInt($value, -1));
    }

    /**
     * Provides the asInt narrowing cases.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function asIntCases(): array
    {
        return [
            'int passes through' => [42, 42],
            'zero passes'        => [0, 0],
            'null falls back'    => [null, -1],
            'string falls back'  => ['42', -1],
            'float falls back'   => [1.5, -1],
            'bool falls back'    => [true, -1],
            'array falls back'   => [[1], -1],
        ];
    }

    /**
     * {@see PayloadReader::nestedString()} narrows a two-level payload: it yields the inner string only
     * when the outer key holds an array and the inner key holds a string, otherwise null.
     *
     * @param array<array-key, mixed> $source   The outer payload array.
     * @param string                  $outerKey The outer key.
     * @param string                  $innerKey The inner key.
     * @param string|null             $expected The expected narrowed nested string.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('nestedStringCases')]
    public function nestedStringNarrowsTwoLevels(array $source, string $outerKey, string $innerKey, ?string $expected): void
    {
        self::assertSame($expected, PayloadReader::nestedString($source, $outerKey, $innerKey));
    }

    /**
     * Provides the nestedString narrowing cases.
     *
     * @return array<string, array{0: array<array-key, mixed>, 1: string, 2: string, 3: string|null}>
     */
    public static function nestedStringCases(): array
    {
        return [
            'outer absent'     => [[], 'facts', 'deathDate', null],
            'outer not array'  => [['facts' => 'scalar'], 'facts', 'deathDate', null],
            'inner absent'     => [['facts' => ['birthDate' => '1900']], 'facts', 'deathDate', null],
            'inner non-string' => [['facts' => ['deathDate' => 1999]], 'facts', 'deathDate', null],
            'happy two-level'  => [['facts' => ['deathDate' => '2026-06-24']], 'facts', 'deathDate', '2026-06-24'],
        ];
    }
}
