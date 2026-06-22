<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the shared band-key normaliser: every known band passes through; any unknown
 * value (a CSS-class-injection vector) collapses to "none".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(BandKey::class)]
#[UsesClass(Band::class)]
final class BandKeyTest extends TestCase
{
    /**
     * A known band label passes through unchanged.
     *
     * @param string $classification The known band label.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('knownBands')]
    public function knownBandPassesThrough(string $classification): void
    {
        self::assertSame($classification, BandKey::normalise($classification));
    }

    /**
     * Provides every band the enum allows.
     *
     * @return array<string, array{0: string}>
     */
    public static function knownBands(): array
    {
        return [
            'strong'   => ['strong'],
            'probable' => ['probable'],
            'possible' => ['possible'],
            'weak'     => ['weak'],
            'none'     => ['none'],
        ];
    }

    /**
     * An unknown value collapses to "none" so it cannot inject an arbitrary CSS class.
     *
     * @param string $classification The unknown classification value.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unknownBands')]
    public function unknownBandCollapsesToNone(string $classification): void
    {
        self::assertSame('none', BandKey::normalise($classification));
    }

    /**
     * Provides unknown classification values that must collapse to "none".
     *
     * @return array<string, array{0: string}>
     */
    public static function unknownBands(): array
    {
        return [
            'unknown label'       => ['super-strong'],
            'CSS-injection value' => ['weak" onmouseover="alert(1)'],
            'empty string'        => [''],
        ];
    }
}
