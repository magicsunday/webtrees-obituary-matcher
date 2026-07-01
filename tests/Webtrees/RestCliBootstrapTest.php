<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\RestCliBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function preg_quote;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Verifies the shared REST CLI bootstrap that `tools/enqueue.php` and `tools/drain.php` both use to
 * resolve their finder connection and ledger root: a valid option set yields the connection and the
 * explicit ledger root, and each misuse (missing base URL, unlocatable default ledger root, invalid
 * connection) throws a RuntimeException carrying the exact operator-facing hint — never the token.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RestCliBootstrap::class)]
#[UsesClass(FinderConnection::class)]
#[UsesClass(WebtreesInstallLocator::class)]
final class RestCliBootstrapTest extends TestCase
{
    /**
     * Builds a throwaway absolute ledger root under the system temp dir (the repo convention), unique per
     * call so parallel workers never collide. resolve() never touches the filesystem for an explicit
     * root, so the directory need not exist.
     *
     * @return string An absolute, unique ledger-root path.
     */
    private function ledgerRoot(): string
    {
        return sys_get_temp_dir() . '/obituary-matcher-' . uniqid('rp-', true);
    }

    /**
     * A valid base URL, token and explicit ledger root resolve to a REST connection carrying the base
     * URL and token, plus the given ledger root verbatim.
     *
     * @return void
     */
    #[Test]
    public function aValidOptionSetResolvesTheConnectionAndExplicitLedgerRoot(): void
    {
        $ledgerRoot = $this->ledgerRoot();

        [$connection, $restPendingRoot] = RestCliBootstrap::resolve(
            'https://finder.example',
            'secret-token',
            $ledgerRoot,
            sys_get_temp_dir(),
        );

        self::assertSame('https://finder.example', $connection->baseUrl());
        self::assertSame('secret-token', $connection->token());
        self::assertSame($ledgerRoot, $restPendingRoot);
    }

    /**
     * An absent or empty token resolves to a connection with no token (a blank field is not a token).
     *
     * @return void
     */
    #[Test]
    public function anAbsentOrEmptyTokenResolvesToNoToken(): void
    {
        $ledgerRoot = $this->ledgerRoot();

        [$connectionForNull]  = RestCliBootstrap::resolve('https://finder.example', null, $ledgerRoot, sys_get_temp_dir());
        [$connectionForBlank] = RestCliBootstrap::resolve('https://finder.example', '', $ledgerRoot, sys_get_temp_dir());

        self::assertNull($connectionForNull->token());
        self::assertNull($connectionForBlank->token());
    }

    /**
     * A missing base URL is a misuse: it throws the required-base-URL hint rather than building a
     * connection.
     *
     * @return void
     */
    #[Test]
    public function aMissingBaseUrlThrowsTheRequiredHint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--base-url=<url> is required', '/') . '/');

        RestCliBootstrap::resolve(null, null, $this->ledgerRoot(), sys_get_temp_dir());
    }

    /**
     * An empty base URL is likewise a misuse and throws the required-base-URL hint.
     *
     * @return void
     */
    #[Test]
    public function anEmptyBaseUrlThrowsTheRequiredHint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--base-url=<url> is required', '/') . '/');

        RestCliBootstrap::resolve('', null, $this->ledgerRoot(), sys_get_temp_dir());
    }

    /**
     * An explicit but empty --rest-pending is a misuse: it throws the absolute-path hint rather than
     * handing an empty ledger root to the transport.
     *
     * @return void
     */
    #[Test]
    public function anEmptyExplicitLedgerRootIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--rest-pending must be a non-empty absolute path', '/') . '/');

        RestCliBootstrap::resolve('https://finder.example', null, '', sys_get_temp_dir());
    }

    /**
     * A relative --rest-pending is rejected: enqueue and drain run from different working directories, so
     * a relative root would resolve to different ledgers and strand an accepted remote job.
     *
     * @return void
     */
    #[Test]
    public function aRelativeExplicitLedgerRootIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--rest-pending must be a non-empty absolute path', '/') . '/');

        RestCliBootstrap::resolve('https://finder.example', null, 'relative/rest-pending', sys_get_temp_dir());
    }

    /**
     * When no explicit ledger root is given and the module root does not sit beside a webtrees install,
     * the default cannot be located, so resolve throws the pass-an-explicit-root hint.
     *
     * @return void
     */
    #[Test]
    public function anUnlocatableDefaultLedgerRootThrowsThePassExplicitHint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Could not locate the running-instance rest-pending dir', '/') . '/');

        // sys_get_temp_dir() is not beside a webtrees config, so the locator cannot resolve a default.
        RestCliBootstrap::resolve('https://finder.example', null, null, sys_get_temp_dir());
    }

    /**
     * A base URL whose scheme is not http(s) is rejected at the FinderConnection source, surfaced as the
     * fixed invalid-connection hint. The hint must not echo the token.
     *
     * @return void
     */
    #[Test]
    public function anInvalidConnectionThrowsTheFixedHintWithoutEchoingTheToken(): void
    {
        try {
            RestCliBootstrap::resolve('file:///etc/passwd', 'secret-token', $this->ledgerRoot(), sys_get_temp_dir());
            self::fail('Expected a RuntimeException for a non-http(s) base URL.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Invalid REST connection', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }
}
