<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-portal coverage-status enum: the three contract wire values map, and any other value is
 * rejected (no lenient default — an unknown status must not silently read as a real portal outcome).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CoverageStatus::class)]
final class CoverageStatusTest extends TestCase
{
    /**
     * Each of the three contract wire values maps to its case.
     *
     * @param string         $wire     The wire value.
     * @param CoverageStatus $expected The expected case.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('wireValues')]
    public function tryFromMapsEachContractWireValue(string $wire, CoverageStatus $expected): void
    {
        self::assertSame($expected, CoverageStatus::tryFrom($wire));
        self::assertSame($wire, $expected->value);
    }

    /**
     * The wire values that must map.
     *
     * @return array<string, array{string, CoverageStatus}>
     */
    public static function wireValues(): array
    {
        return [
            'ok'      => ['ok', CoverageStatus::Ok],
            'failed'  => ['failed', CoverageStatus::Failed],
            'skipped' => ['skipped', CoverageStatus::Skipped],
        ];
    }

    /**
     * An unknown wire value yields null (the validator turns that into a rejection) rather than
     * defaulting to a real status. The value arrives through a provider so it stays a plain `string`
     * (a literal would be const-folded to a statically-known null, making the assertion vacuous).
     *
     * @param string $wire The unknown wire value.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unknownValues')]
    public function tryFromRejectsAnUnknownValue(string $wire): void
    {
        self::assertNull(CoverageStatus::tryFrom($wire));
    }

    /**
     * The wire values that must NOT map (unknown token, empty, wrong case).
     *
     * @return array<string, array{string}>
     */
    public static function unknownValues(): array
    {
        return [
            'unknown token' => ['down'],
            'empty'         => [''],
            'wrong case'    => ['OK'],
        ];
    }
}
