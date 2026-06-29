<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use InvalidArgumentException;
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

    /**
     * A token carrying a control character is rejected at the single source, and the failure message
     * never echoes the secret itself.
     *
     * @return void
     */
    #[Test]
    public function aTokenWithControlCharactersIsRejectedWithoutEchoingIt(): void
    {
        // A control character (e.g. a copy-pasted trailing newline) would make a PSR-7 Authorization
        // header build throw — spilling the token into the throwing frame's arguments — or inject a CRLF
        // header on a non-validating client. It is rejected at this single source, and the failure must
        // never echo the secret itself.
        try {
            FinderConnection::rest('https://finder.example', "secret\ntoken");
            self::fail('Expected an InvalidArgumentException for a control-character token.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    /**
     * A base URL carrying a control character (a CRLF that could inject a header on a non-validating
     * client) is rejected at the single source.
     *
     * @return void
     */
    #[Test]
    public function aBaseUrlWithAControlCharacterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FinderConnection::rest("https://finder.example/\r\nX-Injected: 1", null);
    }

    /**
     * A base URL whose scheme is not http(s) is rejected, so a malformed config value cannot point the
     * transport at a non-HTTP target.
     *
     * @return void
     */
    #[Test]
    public function aBaseUrlWithoutAnHttpSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FinderConnection::rest('file:///etc/passwd', null);
    }

    /**
     * A base URL embedding credentials (a `user:pass@host` userinfo component) is rejected, so a secret
     * can never travel in the base URL — where it would be echoed verbatim into a transport error
     * message — instead of the Authorization header. The message must not echo the embedded credentials.
     *
     * @return void
     */
    #[Test]
    public function aBaseUrlEmbeddingCredentialsIsRejectedWithoutEchoingThem(): void
    {
        try {
            FinderConnection::rest('https://user:s3cr3t@finder.example', null);
            self::fail('Expected an InvalidArgumentException for a credentials-embedding base URL.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('s3cr3t', $exception->getMessage());
            self::assertStringNotContainsString('user', $exception->getMessage());
        }
    }

    /**
     * The scheme-opaque `https:user:pass@host` form (no `//` authority) is rejected without echoing the
     * credentials: parse_url puts `user:pass@host` in the path with no host and no user/pass keys, so the
     * host requirement — not the userinfo check — is what closes this credentials-smuggling form.
     *
     * @return void
     */
    #[Test]
    public function aSchemeOpaqueCredentialsFormIsRejectedWithoutEchoingThem(): void
    {
        try {
            FinderConnection::rest('https:user:s3cr3t@finder.example', null);
            self::fail('Expected an InvalidArgumentException for a scheme-opaque credentials base URL.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('s3cr3t', $exception->getMessage());
        }
    }
}
