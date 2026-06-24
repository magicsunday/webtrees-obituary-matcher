<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use PDOException;
use Throwable;

use function dirname;
use function error_log;
use function fwrite;
use function ini_get;
use function is_array;
use function is_scalar;
use function is_string;
use function parse_ini_file;
use function ucfirst;

use const PHP_EOL;
use const STDERR;

/**
 * A reusable headless webtrees bootstrap for CLI entry points (the queue drain) that have no HTTP
 * request to ride on. It mirrors the exact production boot sequence the integration harness performs
 * — {@see I18N::init()}, a {@see DB::connect()} against the sibling install's `config.ini.php`,
 * {@see Webtrees::bootstrap()} (the DI container) and the element-factory tag registration the
 * routing middleware normally binds — so a CLI sees the same runtime a request does.
 *
 * The boot is guarded by a process-wide static so a re-entry (the test harness boots its own schema
 * first, then re-enters) is a no-op; it also refuses to clobber an already-established database
 * connection, so booting on top of a harness-connected in-memory schema leaves that schema intact.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class HeadlessBootstrap
{
    /**
     * Whether the headless boot has already run in this process. The first call performs the full
     * sequence; every later call is a no-op so a re-entry under the test harness (or a second CLI
     * stage) does not re-run the connect and clobber the live connection.
     */
    private static bool $booted = false;

    /**
     * Boots the webtrees runtime for a headless (request-less) CLI process exactly once. The sequence
     * mirrors {@see \Fisharebest\Webtrees\Cli\Console::bootstrap()} and the integration harness:
     * translations, the database connection against the sibling install's config, the DI container
     * and the GEDCOM element-factory tags. An already-established connection (the test harness
     * connected its own in-memory schema first) is left untouched.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        I18N::init('en-US', true);

        // Only connect when no connection is already established. The integration harness connects an
        // in-memory schema before this boot runs; re-connecting against the sibling config would
        // clobber it (and a CLI run with no harness simply connects here for real).
        if (!self::databaseIsConnected()) {
            self::connectDatabase();
        }

        (new Webtrees())->bootstrap();
        (new Gedcom())->registerTags(Registry::elementFactory(), true);
    }

    /**
     * Logs the first available administrator in as the ambient {@see Auth} principal, so the drain —
     * which reads the ambient user to decide candidate visibility — sees every candidate rather than
     * the no-access guest visitor. There is deliberately NO guest fallback: a headless drain with no
     * administrator account is a misconfiguration that must fail loudly.
     *
     * @param UserService $users The user service used to resolve the administrators (injected so the
     *                           no-admin path is unit-testable with a stub).
     *
     * @return void
     *
     * @throws HeadlessBootstrapException When no administrator account is available.
     */
    public static function loginSystemPrincipal(UserService $users): void
    {
        $admin = $users->administrators()->first();

        if ($admin === null) {
            throw new HeadlessBootstrapException('No admin user available');
        }

        Auth::login($admin);
    }

    /**
     * Boots the request-less webtrees runtime and logs in the system principal for a headless CLI
     * entry point, with the privacy-critical S46 failure handling shared across the queue CLIs. On any
     * boot failure it prints a fixed, config-free category to STDERR, routes the raw detail to the
     * guarded sink ({@see self::logCliError()}) and terminates the process with a non-zero exit code.
     *
     * Only the module's OWN {@see HeadlessBootstrapException} — which carries fixed, config-free
     * messages by construction — is echoed verbatim to STDERR. The catch arms are deliberately ordered
     * PDOException-first: {@see DB}'s connect failure surfaces as a {@see PDOException}, whose message
     * embeds the database host and username; it is caught FIRST and reported only as the fixed
     * `database connection error.` category, so the DSN never reaches STDERR (which cron captures). The
     * module's `HeadlessBootstrapException` is caught second and its message echoed (provably leak-free).
     * Every other {@see Throwable} — including a generic framework `RuntimeException` whose message
     * could embed a path — falls to the final fixed-category arm with the guarded sink, never echoing
     * its message.
     *
     * This method intentionally terminates the process via `exit(1)` on failure; that is acceptable
     * CLI-glue behaviour for a composition-root bootstrap.
     *
     * @param string $cliName The CLI name woven into the fixed STDERR category (e.g. `enqueue`).
     *
     * @return void
     */
    public static function bootForCli(string $cliName): void
    {
        try {
            self::boot();
            self::loginSystemPrincipal(new UserService());
        } catch (PDOException $exception) {
            fwrite(STDERR, 'Headless ' . $cliName . ' bootstrap failed: database connection error.' . PHP_EOL);
            self::logCliError($cliName, $exception);

            exit(1);
        } catch (HeadlessBootstrapException $exception) {
            fwrite(STDERR, 'Headless ' . $cliName . ' bootstrap failed: ' . $exception->getMessage() . PHP_EOL);

            exit(1);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Headless ' . $cliName . ' bootstrap failed: an unexpected error occurred.' . PHP_EOL);
            self::logCliError($cliName, $exception);

            exit(1);
        }
    }

    /**
     * Routes the raw detail of a CLI failure to the configured error sink WITHOUT ever re-leaking it to
     * STDERR (the S46 contract). `error_log()` writes to STDERR in PHP CLI when the `error_log` ini
     * directive is unset or empty — which would re-leak a DSN/credentials-bearing message the callers
     * deliberately keep off STDERR. The detail is therefore only recorded when a real sink (a file or
     * syslog) is configured; otherwise the caller's fixed STDERR category is the only output.
     *
     * @param string    $cliName   The CLI name woven into the sink line prefix (e.g. `enqueue`).
     * @param Throwable $exception The failure whose raw message is routed to the configured sink.
     *
     * @return void
     */
    public static function logCliError(string $cliName, Throwable $exception): void
    {
        $sink = ini_get('error_log');

        if (is_string($sink) && ($sink !== '')) {
            error_log(ucfirst($cliName) . ' CLI error: ' . $exception->getMessage());
        }
    }

    /**
     * Reports whether a usable database connection is already established. Probing the PDO handle is
     * the only reliable signal: an unconnected facade throws when the connection is resolved.
     *
     * @return bool
     */
    private static function databaseIsConnected(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Connects the database from the running webtrees install's `config.ini.php`, mirroring the field
     * names {@see \Fisharebest\Webtrees\Cli\Console::bootstrap()} reads. The install root is the
     * webtrees directory, NOT this module's working directory, so the config is resolved through the
     * layout-independent {@see WebtreesInstallLocator} (relative to this module's root) rather than via
     * {@see Webtrees::CONFIG_FILE} (which only resolves when the cwd is the webtrees root).
     *
     * @return void
     *
     * @throws HeadlessBootstrapException When the webtrees config cannot be located or parsed.
     */
    private static function connectDatabase(): void
    {
        $configFile = (new WebtreesInstallLocator(dirname(__DIR__, 2)))->configFile();

        if ($configFile === null) {
            throw new HeadlessBootstrapException('Could not locate the webtrees config');
        }

        $config = parse_ini_file($configFile);

        if (!is_array($config)) {
            throw new HeadlessBootstrapException('Could not parse the webtrees config');
        }

        DB::connect(
            driver: self::configString($config, 'dbtype', DB::MYSQL),
            host: self::configString($config, 'dbhost', ''),
            port: self::configString($config, 'dbport', ''),
            database: self::configString($config, 'dbname', ''),
            username: self::configString($config, 'dbuser', ''),
            password: self::configString($config, 'dbpass', ''),
            prefix: self::configString($config, 'tblpfx', ''),
            key: self::configString($config, 'dbkey', ''),
            certificate: self::configString($config, 'dbcert', ''),
            ca: self::configString($config, 'dbca', ''),
            verify_certificate: (bool) self::configString($config, 'dbverify', ''),
        );
    }

    /**
     * Reads a single scalar setting from the parsed config as a string, falling back to the supplied
     * default when the key is absent or holds a non-scalar (a sectioned value array `parse_ini_file`
     * would never produce for these flat keys, guarded defensively so the connect arguments stay
     * typed strings rather than `mixed`).
     *
     * @param array<int|string, mixed> $config  The parsed `config.ini.php` contents.
     * @param string                   $key     The setting name to read.
     * @param string                   $default The value to use when the setting is absent or non-scalar.
     *
     * @return string
     */
    private static function configString(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
