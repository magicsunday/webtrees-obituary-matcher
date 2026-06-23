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

        $this->expectException(RuntimeException::class);

        HeadlessBootstrap::loginSystemPrincipal($users);
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

        self::assertSame(42, Auth::id());
        self::assertTrue(Auth::isAdmin($admin));
    }
}
