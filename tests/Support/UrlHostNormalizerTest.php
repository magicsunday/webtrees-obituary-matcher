<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure canonical-host helper across the http(s)-only, lowercase, www-strip and
 * null-rejection cases shared by the enqueue producer and the write-back.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(UrlHostNormalizer::class)]
final class UrlHostNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function hostProvider(): iterable
    {
        yield 'plain https host' => ['https://example.test/notice/1', 'example.test'];
        yield 'http host' => ['http://example.test/x', 'example.test'];
        yield 'uppercase host' => ['https://EXAMPLE.Test/x', 'example.test'];
        yield 'www stripped' => ['https://www.example.test/x', 'example.test'];
        yield 'only leading www stripped' => ['https://wwwexample.test/x', 'wwwexample.test'];
        yield 'non-http scheme is null' => ['ftp://example.test/x', null];
        yield 'no scheme is null' => ['example.test/x', null];
        yield 'unparseable is null' => ['https://', null];
        yield 'control char host null' => ["https://exa\nmple.test/x", null];
    }

    /**
     * @param string      $url      The input URL.
     * @param string|null $expected The expected canonical host.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('hostProvider')]
    public function canonicalHostNormalisesOrRejects(string $url, ?string $expected): void
    {
        self::assertSame($expected, (new UrlHostNormalizer())->canonicalHost($url));
    }
}
