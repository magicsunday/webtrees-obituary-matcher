<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function print_r;

/**
 * Tests the transport connection value object: the two named constructors (file vs REST), the
 * accessor surface and — most importantly — the token-secrecy guarantee. A REST token must stay
 * retrievable for legitimate use yet must never spill through stringification or a debug dump.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderConnection::class)]
final class FinderConnectionTest extends TestCase
{
    #[Test]
    public function fileConnectionExposesNoRestDetails(): void
    {
        $c = FinderConnection::file();

        self::assertSame('file', $c->transport());
        self::assertNull($c->baseUrl());
        self::assertNull($c->token());
    }

    #[Test]
    public function restConnectionCarriesBaseUrlAndToken(): void
    {
        $c = FinderConnection::rest('http://finder:8080', 'secret-token');

        self::assertSame('rest', $c->transport());
        self::assertSame('http://finder:8080', $c->baseUrl());
        self::assertSame('secret-token', $c->token());
    }

    #[Test]
    public function debugOutputRedactsTheToken(): void
    {
        $c = FinderConnection::rest('http://finder:8080', 'secret-token');

        $debug = $c->__debugInfo();

        self::assertSame('***', $debug['token']);
        self::assertStringNotContainsString('secret-token', print_r($debug, true));
    }

    #[Test]
    public function theConnectionHasNoStringificationLeak(): void
    {
        // No __toString: accidental string interpolation cannot spill the token.
        self::assertFalse((new ReflectionClass(FinderConnection::class))->hasMethod('__toString'));
    }

    #[Test]
    public function theTokenStaysRetrievableForLegitimateUse(): void
    {
        self::assertSame('secret-token', FinderConnection::rest('http://x', 'secret-token')->token());
    }
}
