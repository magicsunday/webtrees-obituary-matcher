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
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure config-to-connection resolver shared by the admin control-panel handler and the
 * headless CLI adapters: only the explicit `finder_transport === 'rest'` consent marker with a valid,
 * non-empty base URL yields a connection; a non-rest transport, an empty base URL and a base URL the
 * {@see FinderConnection::rest()} source rejects all resolve to null; and an empty token yields an
 * unauthenticated connection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderConnectionResolver::class)]
#[UsesClass(FinderConnection::class)]
final class FinderConnectionResolverTest extends TestCase
{
    /**
     * A transport other than 'rest' (the legacy 'file' consent value) resolves to null even when a valid
     * base URL is stored: the retained REST creds are never silently reactivated.
     *
     * @return void
     */
    #[Test]
    public function aNonRestTransportResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('file', 'https://finder.example', 'secret'),
        );
    }

    /**
     * The unset-default (empty) transport resolves to null: REST activates only on explicit consent.
     *
     * @return void
     */
    #[Test]
    public function anEmptyTransportResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('', 'https://finder.example', 'secret'),
        );
    }

    /**
     * A rest transport with an empty base URL resolves to null: an unconfigured base URL is "not
     * configured".
     *
     * @return void
     */
    #[Test]
    public function anEmptyBaseUrlResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('rest', '', 'secret'),
        );
    }

    /**
     * A base URL the {@see FinderConnection::rest()} source rejects (a non-http(s) scheme) resolves to
     * null rather than escaping as an exception.
     *
     * @return void
     */
    #[Test]
    public function aRejectedBaseUrlResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('rest', 'ftp://x', 'secret'),
        );
    }

    /**
     * A valid rest config resolves to a connection carrying the stored base URL and token.
     *
     * @return void
     */
    #[Test]
    public function aValidRestConfigResolvesToAConnectionCarryingTheBaseUrlAndToken(): void
    {
        $connection = FinderConnectionResolver::fromConfig('rest', 'https://finder.example', 'secret-token');

        self::assertInstanceOf(FinderConnection::class, $connection);
        self::assertSame('https://finder.example', $connection->baseUrl());
        self::assertSame('secret-token', $connection->token());
    }

    /**
     * An empty stored token yields a connection with a null token (a blank preference is not a token).
     *
     * @return void
     */
    #[Test]
    public function anEmptyTokenResolvesToAConnectionWithoutAToken(): void
    {
        $connection = FinderConnectionResolver::fromConfig('rest', 'https://finder.example', '');

        self::assertInstanceOf(FinderConnection::class, $connection);
        self::assertNull($connection->token());
    }
}
