<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure URL normaliser that derives the SOUR/notice idempotency key.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(UrlNormalizer::class)]
class UrlNormalizerTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function pairs(): array
    {
        return [
            ['https://Example.test/a?utm_source=x&id=7#frag', 'https://example.test/a?id=7', 'tracking + fragment + host case stripped'],
            ['https://example.test/a?id=7', 'https://example.test/a?id=7', 'clean url unchanged'],
            ['https://example.test/a?fbclid=z', 'https://example.test/a', 'fbclid stripped to bare path'],
        ];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function normalizesForIdentity(string $url, string $expected, string $_message): void
    {
        self::assertSame($expected, UrlNormalizer::normalizeForIdentity($url));
    }

    #[Test]
    public function twoTrackingVariantsCollapseToTheSameKey(): void
    {
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a?utm_source=x#f'),
            UrlNormalizer::normalizeForIdentity('https://example.test/a?gclid=y'),
        );
    }
}
