<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\UserService;
use MagicSunday\ObituaryMatcher\Webtrees\HeadlessBootstrap;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration coverage for {@see HeadlessBootstrap} against a real (in-memory) webtrees schema. The
 * {@see IntegrationTestCase} parent already performs the production boot and logs in an administrator,
 * so this test asserts the two contracts the headless drain relies on: {@see HeadlessBootstrap::boot()}
 * is idempotent (a second call is a no-op and leaves the database usable) and
 * {@see HeadlessBootstrap::loginSystemPrincipal()} re-establishes an administrator as the ambient
 * principal against the live user table.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class HeadlessBootstrapTest extends IntegrationTestCase
{
    /**
     * A second {@see HeadlessBootstrap::boot()} after the parent's boot is a no-op (the static guard
     * holds) and does not tear down the already-connected database.
     *
     * @return void
     */
    #[Test]
    public function bootIsIdempotentAndLeavesTheDatabaseUsable(): void
    {
        // The parent setUp already booted webtrees and connected the schema; a re-entry must no-op
        // rather than re-run the connect sequence and clobber the live connection. Pin the connection
        // identity: a dropped idempotency guard would re-run DB::connect() and swap in a fresh PDO, so
        // the SAME PDO instance surviving the second boot is the real proof the no-op held.
        $pdoBefore = DB::connection()->getPdo();

        HeadlessBootstrap::boot();

        self::assertSame(
            $pdoBefore,
            DB::connection()->getPdo(),
            'second boot() must not replace the live PDO connection',
        );

        // The schema is still queryable after the second boot — the admin row the parent seeded is
        // readable, proving the connection survived.
        $admins = DB::table('user')
            ->join('user_setting', 'user_setting.user_id', '=', 'user.user_id')
            ->where('user_setting.setting_name', '=', UserInterface::PREF_IS_ADMINISTRATOR)
            ->where('user_setting.setting_value', '=', '1')
            ->count();

        self::assertSame(1, $admins);
    }

    /**
     * Logging in the system principal through the real {@see UserService} leaves an administrator as
     * the ambient {@see Auth} user.
     *
     * @return void
     */
    #[Test]
    public function loginSystemPrincipalLeavesAnAdministratorLoggedIn(): void
    {
        // Drop the parent's logged-in admin so the assertion proves loginSystemPrincipal re-logs one.
        Auth::logout();

        HeadlessBootstrap::loginSystemPrincipal(new UserService());

        self::assertTrue(Auth::isAdmin());
    }
}
