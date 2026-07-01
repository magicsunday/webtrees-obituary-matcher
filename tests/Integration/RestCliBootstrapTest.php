<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\DB;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Webtrees\FinderCliConfigurationException;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use MagicSunday\ObituaryMatcher\Webtrees\RestCliBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function preg_quote;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Verifies the shared REST CLI bootstrap that `tools/enqueue.php` and `tools/drain.php` both use to
 * resolve their finder connection and ledger root. After the config-only cutover the connection is read
 * from the PERSISTED module preferences (under the same REST consent gate the admin control panel
 * enforces), NOT from `--base-url`/`--token` arguments: a module with `finder_transport === 'rest'` and a
 * valid stored base URL yields the connection; a module with the transport unset/`'file'` or a
 * stored-but-invalid base URL throws the not-configured hint (never echoing the stored credentials); and
 * the `--rest-pending` absolute/empty/root/relative/default guards still hold once the connection
 * resolves. It is an integration test because the module's preferences are DB-backed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RestCliBootstrap::class)]
#[UsesClass(FinderConnection::class)]
#[UsesClass(FinderConnectionResolver::class)]
#[UsesClass(WebtreesInstallLocator::class)]
final class RestCliBootstrapTest extends IntegrationTestCase
{
    /**
     * The module instance under test, with a stable name so its preferences resolve to a seeded row.
     */
    private ObituaryMatcherModule $module;

    /**
     * Builds a module with a stable name and seeds its `module` row so the preference writes have their
     * required foreign-key target.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->module = new ObituaryMatcherModule();
        // The webtrees `module.module_name` column is VARCHAR(32); keep the name within it so a settings
        // write does not truncate on MySQL (SQLite does not enforce the length).
        $this->module->setName('obituary-matcher-cli-test');

        // module_setting.module_name carries a foreign key onto the module table, so a settings write
        // needs a matching module row.
        DB::table('module')->insert([
            'module_name' => $this->module->name(),
            'status'      => 'enabled',
        ]);
    }

    /**
     * Configures the module with a valid REST connection (the consent marker plus a base URL and, when
     * given, a token).
     *
     * @param string      $baseUrl The base URL to persist.
     * @param string|null $token   The token to persist, or null to leave it unset.
     *
     * @return void
     */
    private function configureRest(string $baseUrl, ?string $token = null): void
    {
        $this->module->setPreference('finder_transport', 'rest');
        $this->module->setPreference('finder_base_url', $baseUrl);

        if ($token !== null) {
            $this->module->setPreference('finder_token', $token);
        }
    }

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
     * A module carrying a valid persisted REST connection and an explicit ledger root resolves to a REST
     * connection carrying the stored base URL and token, plus the given ledger root verbatim.
     *
     * @return void
     */
    #[Test]
    public function aConfiguredRestModuleResolvesTheConnectionAndExplicitLedgerRoot(): void
    {
        $this->configureRest('https://finder.example', 'secret-token');
        $ledgerRoot = $this->ledgerRoot();

        [$connection, $restPendingRoot] = RestCliBootstrap::resolve($this->module, $ledgerRoot, sys_get_temp_dir());

        self::assertSame('https://finder.example', $connection->baseUrl());
        self::assertSame('secret-token', $connection->token());
        self::assertSame($ledgerRoot, $restPendingRoot);
    }

    /**
     * A configured REST connection with no stored token resolves to a connection without a token (a blank
     * preference is not a token).
     *
     * @return void
     */
    #[Test]
    public function anAbsentTokenResolvesToNoToken(): void
    {
        $this->configureRest('https://finder.example');

        [$connection] = RestCliBootstrap::resolve($this->module, $this->ledgerRoot(), sys_get_temp_dir());

        self::assertNull($connection->token());
    }

    /**
     * A module with no finder configuration (the unset-default transport) throws the not-configured hint
     * rather than building a connection: REST activates only on explicit consent.
     *
     * @return void
     */
    #[Test]
    public function anUnconfiguredModuleThrowsTheNotConfiguredHint(): void
    {
        $this->expectException(FinderCliConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('The finder connection is not configured', '/') . '/');

        RestCliBootstrap::resolve($this->module, $this->ledgerRoot(), sys_get_temp_dir());
    }

    /**
     * A legacy `file`-transport install with retained REST creds throws the not-configured hint: the
     * dormant creds are never silently reactivated by the CLI. The retained token must not surface in the
     * operator hint.
     *
     * @return void
     */
    #[Test]
    public function aLegacyFileTransportThrowsTheNotConfiguredHintWithoutEchoingTheToken(): void
    {
        $this->module->setPreference('finder_transport', 'file');
        $this->module->setPreference('finder_base_url', 'https://finder.example');
        $this->module->setPreference('finder_token', 'retained-secret');

        try {
            RestCliBootstrap::resolve($this->module, $this->ledgerRoot(), sys_get_temp_dir());
            self::fail('Expected a FinderCliConfigurationException for a legacy file-transport install.');
        } catch (FinderCliConfigurationException $exception) {
            self::assertStringContainsString('The finder connection is not configured', $exception->getMessage());
            self::assertStringNotContainsString('retained-secret', $exception->getMessage());
        }
    }

    /**
     * A stored-but-invalid base URL (a non-http(s) scheme the FinderConnection source rejects) throws the
     * not-configured hint rather than escaping as an exception, and the hint never echoes the stored
     * token.
     *
     * @return void
     */
    #[Test]
    public function aStoredInvalidBaseUrlThrowsTheNotConfiguredHintWithoutEchoingTheToken(): void
    {
        $this->configureRest('ftp://nope', 'secret-token');

        try {
            RestCliBootstrap::resolve($this->module, $this->ledgerRoot(), sys_get_temp_dir());
            self::fail('Expected a FinderCliConfigurationException for a stored-but-invalid base URL.');
        } catch (FinderCliConfigurationException $exception) {
            self::assertStringContainsString('The finder connection is not configured', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }

    /**
     * An explicit but empty --rest-pending is a misuse: with a valid connection configured it still throws
     * the absolute-path hint rather than handing an empty ledger root to the transport.
     *
     * @return void
     */
    #[Test]
    public function anEmptyExplicitLedgerRootIsRejected(): void
    {
        $this->configureRest('https://finder.example');

        $this->expectException(FinderCliConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--rest-pending must be an absolute path', '/') . '/');

        RestCliBootstrap::resolve($this->module, '', sys_get_temp_dir());
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
        $this->configureRest('https://finder.example');

        $this->expectException(FinderCliConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--rest-pending must be an absolute path', '/') . '/');

        RestCliBootstrap::resolve($this->module, 'relative/rest-pending', sys_get_temp_dir());
    }

    /**
     * The filesystem root itself is rejected: a root-level ledger would scan, write and remove *.json
     * entries at `/` rather than in a dedicated directory. A trailing separator is normalised first, so
     * both `/` and `//` are caught.
     *
     * @return void
     */
    #[Test]
    public function aFilesystemRootLedgerRootIsRejected(): void
    {
        $this->configureRest('https://finder.example');

        $this->expectException(FinderCliConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('--rest-pending must be an absolute path', '/') . '/');

        RestCliBootstrap::resolve($this->module, '/', sys_get_temp_dir());
    }

    /**
     * A trailing separator on an otherwise valid absolute ledger root is normalised away, so the resolved
     * root never carries a trailing slash into the downstream path joins.
     *
     * @return void
     */
    #[Test]
    public function aTrailingSeparatorOnTheLedgerRootIsNormalised(): void
    {
        $this->configureRest('https://finder.example');

        [, $restPendingRoot] = RestCliBootstrap::resolve(
            $this->module,
            '/var/lib/webtrees/rest-pending/',
            sys_get_temp_dir(),
        );

        self::assertSame('/var/lib/webtrees/rest-pending', $restPendingRoot);
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
        $this->configureRest('https://finder.example');

        $this->expectException(FinderCliConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Could not locate the running-instance rest-pending dir', '/') . '/');

        // sys_get_temp_dir() is not beside a webtrees config, so the locator cannot resolve a default.
        RestCliBootstrap::resolve($this->module, null, sys_get_temp_dir());
    }
}
