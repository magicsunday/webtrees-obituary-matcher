<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Behavioural tests for the named module class: the `module.php` entry point boots a webtrees tab
 * module, and its tab title is the plain label without a count (the per-individual count is rendered
 * in the tab content, not the title, because the {@see ModuleTabInterface::tabTitle()} contract
 * receives no individual).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryMatcherModule::class)]
final class ObituaryMatcherModuleTest extends TestCase
{
    /**
     * Initialises the webtrees translator so `I18N::translate()` can be called. The setup mode loads
     * no catalogue, so a translated message returns its key verbatim.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        I18N::init('en-US', true);
    }

    #[Test]
    public function isATabModule(): void
    {
        // Boot the real entry point: webtrees loads the module by `require`-ing module.php and using
        // whatever it returns. The return value is untyped here, so this assertion verifies the
        // bootstrap actually yields a tab module instead of restating the class's own `implements`.
        $module = require dirname(__DIR__, 2) . '/module.php';

        self::assertInstanceOf(ModuleTabInterface::class, $module);
    }

    #[Test]
    public function tabTitleIsThePlainLabel(): void
    {
        // With the translator initialised in setup mode no catalogue is loaded, so I18N::translate
        // returns the key verbatim; the per-individual count lives in the tab content, never here.
        self::assertSame('Traueranzeigen', (new ObituaryMatcherModule())->tabTitle());
    }
}
