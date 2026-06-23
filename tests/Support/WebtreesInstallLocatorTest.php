<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function mkdir;
use function realpath;
use function touch;
use function unlink;

/**
 * Tests the layout-independent webtrees install locator against synthetic directory trees for each
 * supported install layout (Composer/source-checkout sibling and `modules_v4` drop-in), plus the
 * no-install-found case.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(WebtreesInstallLocator::class)]
final class WebtreesInstallLocatorTest extends TempDirTestCase
{
    /**
     * Builds the Composer/source-checkout layout: the module at `vendor/magicsunday/<m>` with webtrees
     * as the sibling `vendor/fisharebest/webtrees` carrying a config, and asserts every accessor
     * resolves to the sibling install.
     *
     * @return void
     */
    #[Test]
    public function locatesTheSiblingWebtreesInstall(): void
    {
        $moduleRoot  = $this->tmp . '/vendor/magicsunday/webtrees-obituary-matcher';
        $installRoot = $this->tmp . '/vendor/fisharebest/webtrees';

        mkdir($moduleRoot, 0o700, true);
        mkdir($installRoot . '/data', 0o700, true);
        touch($installRoot . '/data/config.ini.php');

        $this->assertAllAccessorsResolveTo($installRoot, new WebtreesInstallLocator($moduleRoot));
    }

    /**
     * Builds the `modules_v4` drop-in layout: the module at `<webtrees>/modules_v4/<m>` with the
     * webtrees install root two levels up carrying a config, and asserts every accessor resolves to that
     * root.
     *
     * @return void
     */
    #[Test]
    public function locatesTheModulesV4DropInInstall(): void
    {
        $installRoot = $this->tmp . '/webtrees';
        $moduleRoot  = $installRoot . '/modules_v4/webtrees-obituary-matcher';

        mkdir($moduleRoot, 0o700, true);
        mkdir($installRoot . '/data', 0o700, true);
        touch($installRoot . '/data/config.ini.php');

        $this->assertAllAccessorsResolveTo($installRoot, new WebtreesInstallLocator($moduleRoot));
    }

    /**
     * Builds the webtrees-as-root-package layout: the module is `composer require`d into a webtrees
     * source install, landing at `<webtrees>/vendor/magicsunday/<m>` with the webtrees install root
     * three levels up carrying a config, and asserts every accessor resolves to that root.
     *
     * @return void
     */
    #[Test]
    public function locatesTheWebtreesAsRootPackageInstall(): void
    {
        $installRoot = $this->tmp . '/webtrees';
        $moduleRoot  = $installRoot . '/vendor/magicsunday/webtrees-obituary-matcher';

        mkdir($moduleRoot, 0o700, true);
        mkdir($installRoot . '/data', 0o700, true);
        touch($installRoot . '/data/config.ini.php');

        $this->assertAllAccessorsResolveTo($installRoot, new WebtreesInstallLocator($moduleRoot));
    }

    /**
     * Prefers the sibling layout when BOTH a sibling install and a two-up install carry a config — the
     * sibling candidate is probed first, so a shared-vendor checkout never mistakes the vendor root for
     * the install root.
     *
     * @return void
     */
    #[Test]
    public function prefersTheSiblingInstallWhenBothLayoutsResolve(): void
    {
        $moduleRoot  = $this->tmp . '/vendor/magicsunday/webtrees-obituary-matcher';
        $siblingRoot = $this->tmp . '/vendor/fisharebest/webtrees';
        $twoUpRoot   = $this->tmp . '/vendor';

        mkdir($moduleRoot, 0o700, true);
        mkdir($siblingRoot . '/data', 0o700, true);
        mkdir($twoUpRoot . '/data', 0o700, true);
        touch($siblingRoot . '/data/config.ini.php');
        touch($twoUpRoot . '/data/config.ini.php');

        $locator = new WebtreesInstallLocator($moduleRoot);

        self::assertSame(realpath($siblingRoot), $locator->installRoot());

        // Negative control: with the sibling config removed, the two-up candidate must still resolve,
        // proving both candidates were live and the candidate ordering (sibling before two-up) is what
        // selected the sibling above — not the two-up candidate being silently absent.
        unlink($siblingRoot . '/data/config.ini.php');

        self::assertSame(realpath($twoUpRoot), (new WebtreesInstallLocator($moduleRoot))->installRoot());
    }

    /**
     * Returns null from every accessor when no candidate install carries a `data/config.ini.php`.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenNoInstallIsFound(): void
    {
        $moduleRoot = $this->tmp . '/vendor/magicsunday/webtrees-obituary-matcher';

        mkdir($moduleRoot, 0o700, true);

        $locator = new WebtreesInstallLocator($moduleRoot);

        self::assertNull($locator->installRoot());
        self::assertNull($locator->configFile());
        self::assertNull($locator->dataDir());
        self::assertNull($locator->defaultQueueRoot());
    }

    /**
     * Asserts that all four accessors resolve to the canonicalised install root and its derived paths.
     *
     * @param string                 $installRoot The (un-canonicalised) expected install root directory.
     * @param WebtreesInstallLocator $locator     The locator under test.
     *
     * @return void
     */
    private function assertAllAccessorsResolveTo(string $installRoot, WebtreesInstallLocator $locator): void
    {
        $expectedRoot = realpath($installRoot);

        self::assertSame($expectedRoot, $locator->installRoot());
        self::assertSame($expectedRoot . '/data/config.ini.php', $locator->configFile());
        self::assertSame($expectedRoot . '/data', $locator->dataDir());
        self::assertSame($expectedRoot . '/data/obituary-matcher/queue', $locator->defaultQueueRoot());
    }
}
