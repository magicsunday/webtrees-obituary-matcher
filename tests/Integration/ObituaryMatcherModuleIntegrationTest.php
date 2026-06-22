<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Ui\SuggestionTabPresenter;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function bin2hex;
use function glob;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

/**
 * Boots a real webtrees runtime, imports a two-person fixture tree and drives
 * the actual {@see ObituaryMatcherModule::hasTabContent()} path of the
 * {@see \Fisharebest\Webtrees\Module\ModuleTabInterface} against a real
 * {@see Individual}. A test subclass overrides the
 * {@see ObituaryMatcherModule::presenterForTree()} seam to read a tree-scoped
 * temp store instead of touching the live data directory, so the assertion is
 * the genuine module-to-individual visibility decision and not a stand-in.
 *
 * Manager-only tab access is enforced by webtrees' `module_privacy` plumbing
 * (the `ModuleTabInterface` access check), not by the module's own
 * `hasTabContent()`, so it cannot be exercised here without the framework's
 * access-control machinery; it is covered by the manual smoke and stands as a
 * framework guarantee.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryMatcherModule::class)]
#[UsesClass(SuggestionTabPresenter::class)]
#[UsesClass(SuggestionViewModel::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchSeeder::class)]
#[UsesClass(MatchStatus::class)]
final class ObituaryMatcherModuleIntegrationTest extends IntegrationTestCase
{
    /**
     * The tab is offered for an individual that has a seeded non-terminal match
     * and hidden for one that has none, decided through the real module path
     * against real webtrees individuals.
     *
     * @return void
     */
    #[Test]
    public function tabVisibleOnlyForAnIndividualWithANonTerminalMatch(): void
    {
        $gedcom = "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n0 @I2@ INDI\n1 NAME Anna /Beispiel/\n";
        $tree   = $this->importFixtureTree($gedcom);

        $dir = sys_get_temp_dir() . '/om-mod-' . bin2hex(random_bytes(4));
        MatchSeeder::seed(new FileMatchStore($dir), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $module = new class($dir) extends ObituaryMatcherModule {
            public function __construct(private readonly string $dir)
            {
                // AbstractModule (and its ModuleInterface) declare no constructor,
                // so there is no parent constructor to chain to; the promoted
                // $dir property is the only state this test subclass needs.
            }

            protected function presenterForTree(Tree $tree): SuggestionTabPresenter
            {
                return new SuggestionTabPresenter(new FileMatchStore($this->dir));
            }
        };

        $withMatch    = $this->individual('I1', $tree);
        $withoutMatch = $this->individual('I2', $tree);

        self::assertInstanceOf(Individual::class, $withMatch);
        self::assertInstanceOf(Individual::class, $withoutMatch);

        self::assertTrue($module->hasTabContent($withMatch));
        self::assertFalse($module->hasTabContent($withoutMatch));

        $files = glob($dir . '/*');
        array_map('unlink', $files === false ? [] : $files);
        rmdir($dir);
    }
}
