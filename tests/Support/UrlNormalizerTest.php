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
final class UrlNormalizerTest extends TestCase
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
            ['https://example.test/a?promo.code=1', 'https://example.test/a?promo.code=1', 'dotted param name preserved (not mangled to promo_code)'],
        ];
    }

    /**
     * Normalising a URL strips host case, tracking parameters and the fragment down to the stable
     * identity key, while leaving a clean URL unchanged.
     */
    #[Test]
    #[DataProvider('pairs')]
    public function normalizesForIdentity(string $url, string $expected, string $_message): void
    {
        self::assertSame($expected, UrlNormalizer::normalizeForIdentity($url));
    }

    /**
     * Two URLs differing only in their tracking parameters and fragment normalise to the same
     * identity key.
     */
    #[Test]
    public function twoTrackingVariantsCollapseToTheSameKey(): void
    {
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a?utm_source=x#f'),
            UrlNormalizer::normalizeForIdentity('https://example.test/a?gclid=y'),
        );
    }

    /**
     * Provides parseable-but-scheme-less or host-less inputs that have no canonical absolute-URL
     * identity. Each must round-trip as the trimmed, lower-cased input rather than yielding a
     * malformed "://..." key.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function schemelessOrHostlessInputs(): array
    {
        return [
            'host-less relative path'   => ['/relative', '/relative'],
            'scheme-less host and path' => ['Example.test/x', 'example.test/x'],
            'urn without authority'     => ['urn:isbn:123', 'urn:isbn:123'],
        ];
    }

    /**
     * A scheme-less or host-less URL has no canonical absolute-URL identity, so it normalises to the
     * trimmed, lower-cased input (a stable self-key) and NEVER to a malformed "://..." value.
     */
    #[Test]
    #[DataProvider('schemelessOrHostlessInputs')]
    public function schemelessOrHostlessInputRoundTripsToItself(string $url, string $expected): void
    {
        $result = UrlNormalizer::normalizeForIdentity($url);

        self::assertSame($expected, $result);
        self::assertStringStartsNotWith('://', $result);
    }

    /**
     * The URL port is part of the identity: a notice served on an explicit non-default port is a
     * distinct resource from the same path on the default port, so the port is kept in the key and
     * the two must NOT collapse onto one identity.
     */
    #[Test]
    public function preservesThePortInTheIdentityKey(): void
    {
        self::assertSame(
            'https://example.test:8080/a',
            UrlNormalizer::normalizeForIdentity('https://example.test:8080/a'),
        );

        self::assertNotSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a'),
            UrlNormalizer::normalizeForIdentity('https://example.test:8080/a'),
        );
    }

    /**
     * Two URLs differing only in the case of their scheme normalise to the same lower-cased identity
     * key, so a scheme-case variant cannot leak past the de-dup (ResponseReader lower-cases the
     * scheme only for its allow-list check, not for the identity key).
     */
    #[Test]
    public function schemeCaseDoesNotLeakPastTheIdentityKey(): void
    {
        self::assertSame(
            'http://example.test/x',
            UrlNormalizer::normalizeForIdentity('HTTP://Example.test/x'),
        );
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('http://Example.test/x'),
            UrlNormalizer::normalizeForIdentity('HTTP://Example.test/x'),
        );
    }
}
