<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the shared source-link guard: the HTTP(S) scheme allow-list and the host
 * extraction, including the hostile-scheme refusal that keeps a `javascript:`/`data:` URL out of an
 * anchor href.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SourceLink::class)]
final class SourceLinkTest extends TestCase
{
    /**
     * An HTTP(S) URL yields the href verbatim and its parsed host.
     *
     * @return void
     */
    #[Test]
    public function httpUrlYieldsHrefAndHost(): void
    {
        $link = SourceLink::fromUrl('https://trauer.example/anzeige');

        self::assertSame('https://trauer.example/anzeige', $link->href);
        self::assertSame('trauer.example', $link->host);
    }

    /**
     * The scheme allow-list is case-insensitive.
     *
     * @return void
     */
    #[Test]
    public function uppercaseSchemeIsAllowed(): void
    {
        $link = SourceLink::fromUrl('HTTP://trauer.example/x');

        self::assertSame('HTTP://trauer.example/x', $link->href);
        self::assertSame('trauer.example', $link->host);
    }

    /**
     * A hostile non-HTTP scheme is refused: both the href and the host are null so it can never reach
     * an anchor.
     *
     * @param string $url The hostile URL.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('hostileSchemes')]
    public function hostileSchemeIsRefused(string $url): void
    {
        $link = SourceLink::fromUrl($url);

        self::assertNull($link->href);
        self::assertNull($link->host);
    }

    /**
     * Provides the hostile schemes that must be refused.
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileSchemes(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme'       => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    /**
     * An HTTP scheme without a parseable host yields the href but a null host.
     *
     * @return void
     */
    #[Test]
    public function httpWithoutHostYieldsHrefButNullHost(): void
    {
        $link = SourceLink::fromUrl('https:///path-only');

        self::assertSame('https:///path-only', $link->href);
        self::assertNull($link->host);
    }
}
