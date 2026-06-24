<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Illuminate\Support\Collection;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function fclose;
use function file_get_contents;
use function ini_get;
use function ini_set;
use function preg_quote;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const PHP_BINARY;

/**
 * Unit coverage for {@see HeadlessBootstrap::loginSystemPrincipal()} — the system-principal login the
 * headless drain runs so it sees every candidate. Both branches are exercised without a database: the
 * injected {@see UserService} is stubbed, so the no-admin path (which must refuse rather than fall
 * back to the guest visitor) and the happy path (which logs the administrator in) are both pinned
 * here. {@see Auth::login()} only writes the user id into `$_SESSION`, so the session-level assertion
 * needs no webtrees runtime; {@see Auth::isAdmin()} reads the explicit user's preference directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(HeadlessBootstrap::class)]
final class HeadlessBootstrapLoginTest extends TestCase
{
    /**
     * Reset the session-backed auth state so neither branch leaks a logged-in principal into the
     * sibling test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Session::clear();
    }

    /**
     * Reset the session-backed auth state so neither branch leaks a logged-in principal into the
     * sibling test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Session::clear();

        parent::tearDown();
    }

    /**
     * With no administrator account available the headless drain must refuse loudly rather than run
     * as the no-access guest visitor.
     *
     * @return void
     */
    #[Test]
    public function loginSystemPrincipalThrowsWhenNoAdministratorExists(): void
    {
        $users = self::createStub(UserService::class);
        $users->method('administrators')->willReturn(new Collection());

        try {
            HeadlessBootstrap::loginSystemPrincipal($users);

            self::fail('loginSystemPrincipal() must refuse when no administrator account exists.');
        } catch (RuntimeException $exception) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote('No admin user available', '/') . '/',
                $exception->getMessage(),
            );
        }

        // The refuse path must establish NO principal — not even a partial/guest one — so a later
        // drain stage cannot run with the no-access visitor masquerading as the system principal.
        self::assertNull(Auth::id(), 'no guest principal established on the refuse path');
    }

    /**
     * The first available administrator is logged in as the ambient principal, so the drain that
     * reads the ambient {@see Auth} user sees an administrator.
     *
     * @return void
     */
    #[Test]
    public function loginSystemPrincipalLogsTheAdministratorIn(): void
    {
        $admin = self::createStub(UserInterface::class);
        $admin->method('id')->willReturn(42);
        $admin->method('getPreference')->willReturn('1');

        $users = self::createStub(UserService::class);
        $users->method('administrators')->willReturn(new Collection([$admin]));

        HeadlessBootstrap::loginSystemPrincipal($users);

        // Auth::id() reads the session id Auth::login() wrote, so it proves the right principal (42)
        // was actually logged in. Asserting Auth::isAdmin($admin) would be tautological — it re-reads
        // the SAME stub's getPreference rather than the established session principal.
        self::assertSame(42, Auth::id());
    }

    /**
     * With a real `error_log` sink configured (a file), the raw failure detail IS recorded under the
     * capitalised CLI-name prefix — the administrator-facing diagnostic the fixed STDERR category omits.
     *
     * @return void
     */
    #[Test]
    public function logCliErrorWritesTheDetailWhenAnErrorLogSinkIsConfigured(): void
    {
        $sink     = tempnam(sys_get_temp_dir(), 'obituary-clierror-');
        $previous = ini_get('error_log');

        self::assertIsString($sink, 'a temporary sink file must be creatable');

        ini_set('error_log', $sink);

        try {
            HeadlessBootstrap::logCliError('enqueue', new RuntimeException('Access denied for user'));

            $written = file_get_contents($sink);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($sink);
        }

        self::assertIsString($written);
        self::assertStringContainsString('Enqueue CLI error: Access denied for user', $written);
    }

    /**
     * With NO `error_log` sink configured the credentials-bearing detail must NOT leak — the S46
     * DSN-leak guard. PHP CLI's `error_log()` falls back to STDERR when the `error_log` directive is
     * empty, so the guard must skip the call entirely; otherwise the message the fixed STDERR category
     * deliberately omits would re-leak there. This is asserted in an ISOLATED subprocess (the only
     * place STDERR can be observed without destroying the test runner's own STDERR), with a positive
     * control proving the harness actually exercises a real failure detail.
     *
     * @return void
     */
    #[Test]
    public function logCliErrorWritesNothingToStderrWhenNoErrorLogSinkIsConfigured(): void
    {
        // Positive control: with the guard REMOVED (error_log called unconditionally), the leak text
        // WOULD reach STDERR under an empty directive — proving the subprocess harness can observe it.
        $leaked = self::runLogCliErrorInSubprocess(false);
        self::assertStringContainsString(
            'Access denied for user',
            $leaked,
            'the subprocess harness must be able to observe a STDERR leak (positive control)',
        );

        // The real guard: error_log() is skipped when no sink is configured, so the credentials-bearing
        // detail never reaches STDERR.
        $guarded = self::runLogCliErrorInSubprocess(true);
        self::assertStringNotContainsString(
            'Access denied for user',
            $guarded,
            'no credentials-bearing detail may reach STDERR when no real sink is configured',
        );
    }

    /**
     * Runs the `error_log`-guarded detail sink in an isolated PHP subprocess under an EMPTY `error_log`
     * directive and returns whatever it wrote to STDERR. The subprocess either invokes the real
     * {@see HeadlessBootstrap::logCliError()} (the production guard) or the same body WITHOUT the guard
     * (the positive control), so the test can prove the guard is what keeps the credentials-bearing
     * message off STDERR rather than some ambient configuration.
     *
     * @param bool $useGuard Whether to call the guarded production method (true) or the unguarded
     *                       control body (false).
     *
     * @return string The subprocess STDERR contents.
     */
    private static function runLogCliErrorInSubprocess(bool $useGuard): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/.build/vendor/autoload.php', true);
        $message  = var_export('Access denied for user', true);

        // The empty `error_log` directive routes error_log() to STDERR in CLI; the guard must suppress
        // the call. The control branch calls error_log() directly to prove the leak is observable.
        $call = $useGuard
            ? '\\MagicSunday\\ObituaryMatcher\\Webtrees\\HeadlessBootstrap::logCliError('
                . '\'enqueue\', new \\RuntimeException(' . $message . '));'
            : 'error_log(\'Enqueue CLI error: \' . ' . $message . ');';

        // `php -r` evaluates code WITHOUT a leading `<?php` tag; `use` is illegal mid-script there, so
        // the class and exception are referenced fully-qualified instead.
        $script = 'require ' . $autoload . ';'
            . 'ini_set(\'error_log\', \'\');'
            . $call;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = proc_open([PHP_BINARY, '-d', 'error_log=', '-r', $script], $descriptors, $pipes);

        self::assertIsResource($process, 'the logCliError subprocess must start');

        // Close the child's stdin immediately (no input) and drain stdout before stderr so a full pipe
        // cannot deadlock the child.
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stderr === false ? '' : $stderr;
    }
}
