<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Services\ModuleService;

/**
 * Resolves the REGISTERED {@see ObituaryMatcherModule} instance for the headless CLI adapters. The module
 * NAME is install-derived — a vendor install derives it through webtrees' VendorModuleService
 * `generateModuleName`, a `modules_v4` drop-in from the directory basename — so the CLI cannot hardcode a
 * name and must instead resolve the same registered instance the admin control panel drives, so a
 * `getPreference()` read hits the same `module_setting` rows the panel wrote.
 *
 * Resolution goes through {@see ModuleService::findByInterface()} with `$include_disabled = true`: that
 * call runs webtrees' module discovery (core + custom + vendor) and returns every registered custom
 * module, out of which the first {@see ObituaryMatcherModule} instance is picked. It returns null when the
 * module is not installed/registered in this instance.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class CliModuleResolver
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Resolves the registered {@see ObituaryMatcherModule} instance, or null when the module is not
     * installed/enabled in this webtrees instance. Disabled modules are included so the CLI still resolves
     * an admin-disabled install rather than silently failing to find it.
     *
     * This discovery path (finding the registered vendor/drop-in module through {@see ModuleService})
     * runs only in the live webtrees runtime — the unit/integration suite does not register the vendor
     * module — so it is exercised by a live smoke, not by an automated test.
     *
     * @return ObituaryMatcherModule|null The registered module instance, or null when not installed.
     */
    public static function resolveActiveModule(): ?ObituaryMatcherModule
    {
        $modules = (new ModuleService())->findByInterface(ModuleCustomInterface::class, true);

        foreach ($modules as $module) {
            if ($module instanceof ObituaryMatcherModule) {
                return $module;
            }
        }

        return null;
    }
}
