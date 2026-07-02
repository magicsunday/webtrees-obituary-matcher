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
     * The byte-for-byte pinned algorithm (issue #67): the residual query is sorted with the explicit
     * SORT_STRING bytewise order (repeated keys preserved), and both the path and each query pair are
     * percent-normalised per RFC 3986 §6.2.2 — escape hex is upper-cased, an escaped unreserved byte is
     * decoded, every other escape is kept encoded, and a literal `+` is kept (never folded to a space).
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function deterministicVectors(): array
    {
        return [
            // Query params sort by full bytes, ascending.
            'query sorts bytewise ascending' => ['https://example.test/a?b=2&a=1', 'https://example.test/a?a=1&b=2'],
            // A shorter pair sorts before its own longer prefix-extension, and a repeated key is kept.
            'repeated key + prefix order' => ['https://example.test/a?b=2&a=10&a=1', 'https://example.test/a?a=1&a=10&b=2'],
            // An escaped reserved byte (encoded slash) is kept encoded, only its hex case is normalised.
            'reserved escape hex upper-cased' => ['https://example.test/a?x=%2fy', 'https://example.test/a?x=%2Fy'],
            // An escaped unreserved byte in the query is decoded.
            'unreserved query escape decoded' => ['https://example.test/a?x=%7Ez', 'https://example.test/a?x=~z'],
            // An escaped unreserved byte in the path is decoded (and hex-case-insensitively so).
            'unreserved path escape decoded' => ['https://example.test/%41%62', 'https://example.test/Ab'],
            // An encoded space stays encoded (it is a reserved-context byte, kept as %20).
            'encoded space stays encoded' => ['https://example.test/a?x=%20y', 'https://example.test/a?x=%20y'],
            // A literal '+' is a distinct query byte, NOT folded to a space.
            'literal plus kept verbatim' => ['https://example.test/a?x=a+b', 'https://example.test/a?x=a+b'],
            // An encoded slash in the path is kept encoded (distinct from a literal '/'), hex upper-cased.
            'encoded path slash kept, hex upper' => ['https://example.test/a%2fb', 'https://example.test/a%2Fb'],
            // A percent-encoded tracking name ("%75tm_source" = "utm_source") is decoded and THEN stripped
            // on the same pass, so the algorithm stays idempotent (the sibling idempotence test re-runs it).
            'encoded tracking name stripped' => ['https://example.test/a?%75tm_source=x&id=1', 'https://example.test/a?id=1'],
            // Decoding an unreserved escape can materialise a dot-segment; it is kept verbatim (no collapse).
            'materialised dot-segment not collapsed' => ['https://example.test/a/%2E%2E/b', 'https://example.test/a/../b'],
            // Bare-name pairs sort in unsigned-byte order ("1"<"2"<"A"<"a"), ruling out numeric/locale/case sorts.
            'bare names sort by unsigned byte' => ['https://example.test/a?a&2&10&A', 'https://example.test/a?10&2&A&a'],
            // Empty pairs (a "&&" run / trailing "&") are dropped; a bare "a" stays distinct from "a=".
            'empty pairs dropped, a vs a= distinct' => ['https://example.test/a?b&&a=&a&', 'https://example.test/a?a&a=&b'],
            // A malformed escape (bad hex, one digit, a bare %) is left byte-for-byte unchanged.
            'malformed escape left verbatim' => ['https://example.test/a?x=%GG%1%', 'https://example.test/a?x=%GG%1%'],
            // An encoded '=' (%3D) is reserved, so it stays encoded and is NOT the name/value separator:
            // the name is "a%3Db", the value "c" (only the hex case is normalised).
            'encoded equals is not a separator' => ['https://example.test/a?a%3db=c', 'https://example.test/a?a%3Db=c'],
        ];
    }

    /**
     * Every deterministic-algorithm vector normalises to its exact documented byte form, pinning the
     * sort order and the percent-encoding rules a clean-room finder must reproduce.
     */
    #[Test]
    #[DataProvider('deterministicVectors')]
    public function normalisesToTheDocumentedByteForm(string $url, string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalizeForIdentity($url));
    }

    /**
     * A literal `+` and an encoded space `%20` are DISTINCT query bytes and must NOT collapse onto one
     * identity key: the generic URI query treats `+` as a literal, so a finder that means "space" must
     * emit `%20`.
     */
    #[Test]
    public function aLiteralPlusDoesNotCollapseWithAnEncodedSpace(): void
    {
        self::assertNotSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a?x=a+b'),
            UrlNormalizer::normalizeForIdentity('https://example.test/a?x=a%20b'),
        );
    }

    /**
     * The normalisation is idempotent: normalising an already-normalised key yields the same bytes, so a
     * value that has passed through the algorithm once is a fixed point (a decoded unreserved byte is
     * never a `%`, and an already-upper-cased escape re-emits unchanged, so no second pass can change it).
     *
     * @param string $url       The raw input URL.
     * @param string $_expected The documented normalised form (asserted by the sibling vector test).
     *
     * @return void
     */
    #[Test]
    #[DataProvider('deterministicVectors')]
    public function normalisationIsIdempotent(string $url, string $_expected): void
    {
        $once  = UrlNormalizer::normalizeForIdentity($url);
        $twice = UrlNormalizer::normalizeForIdentity($once);

        self::assertSame($once, $twice);
    }

    /**
     * A scheme+host URL without a path (no trailing slash) collapses onto the root slash, so the
     * bare-host and trailing-slash forms of the same root resource share one identity key. Only the
     * root case collapses: a non-root path keeps its distinct trailing-slash variant.
     */
    #[Test]
    public function emptyPathCollapsesOntoTheRootSlash(): void
    {
        self::assertSame(
            'https://example.test/',
            UrlNormalizer::normalizeForIdentity('https://example.test'),
        );
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('https://example.test'),
            UrlNormalizer::normalizeForIdentity('https://example.test/'),
        );
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
     * The tracking-parameter strip list matches case-insensitively, so an upper- or mixed-case
     * tracking parameter ("UTM_SOURCE", "Fbclid", "GCLID") is stripped just like its lower-case form
     * and cannot leak past the de-dup. A non-tracking parameter is retained with its original name
     * case preserved (query parameter names are case-sensitive in general; only the tracking-strip
     * match is case-insensitive).
     */
    #[Test]
    public function matchesTrackingParametersCaseInsensitively(): void
    {
        // An upper-case utm_* prefix is stripped, the non-tracking "id" survives with its value.
        self::assertSame(
            'https://example.test/a?id=7',
            UrlNormalizer::normalizeForIdentity('https://example.test/a?UTM_SOURCE=x&id=7'),
        );

        // A mixed-case fbclid is stripped down to the bare path, mirroring the lower-case form.
        self::assertSame(
            'https://example.test/a',
            UrlNormalizer::normalizeForIdentity('https://example.test/a?Fbclid=z'),
        );

        // An upper-case gclid is stripped too.
        self::assertSame(
            'https://example.test/a',
            UrlNormalizer::normalizeForIdentity('https://example.test/a?GCLID=y'),
        );

        // A non-tracking parameter keeps its original name case (only the strip match is folded).
        self::assertSame(
            'https://example.test/a?Ref=Foo',
            UrlNormalizer::normalizeForIdentity('https://example.test/a?Ref=Foo'),
        );
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
     * A port equal to the scheme's default (http->80, https->443) names the very same resource as the
     * portless URL, so it is stripped and the two collapse onto one identity key. A non-default port
     * stays in the key and keeps the URL distinct.
     */
    #[Test]
    public function stripsADefaultPortButKeepsANonDefaultPort(): void
    {
        // The default https port collapses onto the portless form.
        self::assertSame(
            'https://example.test/a',
            UrlNormalizer::normalizeForIdentity('https://example.test:443/a'),
        );
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a'),
            UrlNormalizer::normalizeForIdentity('https://example.test:443/a'),
        );

        // The default http port collapses onto the portless form.
        self::assertSame(
            'http://example.test/a',
            UrlNormalizer::normalizeForIdentity('http://example.test:80/a'),
        );
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('http://example.test/a'),
            UrlNormalizer::normalizeForIdentity('http://example.test:80/a'),
        );

        // A non-default port is kept and stays distinct from the portless form.
        self::assertSame(
            'https://example.test:8443/a',
            UrlNormalizer::normalizeForIdentity('https://example.test:8443/a'),
        );
        self::assertNotSame(
            UrlNormalizer::normalizeForIdentity('https://example.test/a'),
            UrlNormalizer::normalizeForIdentity('https://example.test:8443/a'),
        );
    }

    /**
     * A host carrying an uppercase non-ASCII (UTF-8 IDN) character is lower-cased correctly, so the
     * upper- and lower-case host variants of the same IDN host collapse onto one identity key. Plain
     * strtolower() is byte-wise ASCII-only and would leave "Ä"/"É" untouched, leaking a duplicate
     * identity; mb_strtolower() folds the case so the two variants share one key.
     */
    #[Test]
    public function lowerCasesANonAsciiHostMultibyteSafely(): void
    {
        // "Ä" lower-cases to "ä" under mb_strtolower but stays "Ä" under plain strtolower.
        self::assertSame(
            'https://äöü.test/a',
            UrlNormalizer::normalizeForIdentity('https://Äöü.test/a'),
        );

        // The upper- and lower-case host variants collapse onto one identity key.
        self::assertSame(
            UrlNormalizer::normalizeForIdentity('https://äöü.test/a'),
            UrlNormalizer::normalizeForIdentity('https://Äöü.test/a'),
        );
    }

    /**
     * Two URLs differing only in the case of their scheme normalise to the same lower-cased identity
     * key, so a scheme-case variant cannot leak past the de-dup (the response validator lower-cases the
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
