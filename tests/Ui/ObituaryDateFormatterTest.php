<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the shared death-date formatter: ISO conversion, non-ISO passthrough and the
 * null case.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryDateFormatter::class)]
final class ObituaryDateFormatterTest extends TestCase
{
    /**
     * The formatter converts an ISO date, passes any other shape through and maps null to null.
     *
     * @param string|null $raw      The raw extracted death date.
     * @param string|null $expected The expected formatted result.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('cases')]
    public function formatsIsoAndPassesEverythingElseThrough(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, ObituaryDateFormatter::toGerman($raw));
    }

    /**
     * Provides the formatter cases.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function cases(): array
    {
        return [
            'ISO date is converted to German'         => ['2023-09-04', '04.09.2023'],
            'null passes through as null'             => [null, null],
            'non-ISO approximate date passes through' => ['um 1923', 'um 1923'],
            'partial ISO date is not converted'       => ['2023-09', '2023-09'],
            'empty string passes through'             => ['', ''],
        ];
    }
}
