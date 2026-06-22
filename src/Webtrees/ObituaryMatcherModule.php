<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Ui\SuggestionTabPresenter;
use Override;

use function realpath;
use function view;

/**
 * The webtrees custom-module entry point. It registers the obituary matcher as an individual tab
 * whose content is the per-candidate suggestion list, wiring the read-only {@see SuggestionTabPresenter}
 * to a tree-scoped match store built by {@see MatchStoreFactory}. The tab is the composition boundary:
 * it pulls the stored suggestions for the individual on display and renders them through the module's
 * own view namespace, while the matching engine itself stays free of webtrees.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryMatcherModule extends AbstractModule implements ModuleCustomInterface, ModuleTabInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;

    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string The module title shown in the control panel.
     */
    #[Override]
    public function title(): string
    {
        return I18N::translate('Obituary Matcher');
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string The one-sentence module description.
     */
    #[Override]
    public function description(): string
    {
        return I18N::translate('Match individuals against public death notices to suggest missing death dates.');
    }

    /**
     * The person or organisation who created this module.
     *
     * @return string The module author's name.
     */
    #[Override]
    public function customModuleAuthorName(): string
    {
        return 'Rico Sonntag';
    }

    /**
     * The version of this module.
     *
     * @return string The module version string.
     */
    #[Override]
    public function customModuleVersion(): string
    {
        return '0.1.0-dev';
    }

    /**
     * Where does this module store its resources (views, assets)?
     *
     * @return string The absolute path to the module's resources folder, with a trailing slash.
     */
    #[Override]
    public function resourcesFolder(): string
    {
        return __DIR__ . '/../../resources/';
    }

    /**
     * Bootstrap the module by registering its view namespace, so the tab template resolves under the
     * module name. The registration is guarded by {@see realpath()} so a missing resources directory
     * leaves the namespace unregistered instead of pointing it at a non-existent path.
     *
     * @return void
     */
    #[Override]
    public function boot(): void
    {
        $views = realpath($this->resourcesFolder() . 'views/');

        if ($views !== false) {
            View::registerNamespace($this->name(), $views . '/');
        }
    }

    /**
     * The label shown on the tab. This is the plain module label without a count: the
     * {@see ModuleTabInterface::tabTitle()} contract receives no individual, so the per-candidate
     * suggestion count is rendered inside the tab content instead.
     *
     * @return string The tab label.
     */
    #[Override]
    public function tabTitle(): string
    {
        return I18N::translate('Traueranzeigen');
    }

    /**
     * The default position of this tab among the individual tabs.
     *
     * @return int The default tab order.
     */
    #[Override]
    public function defaultTabOrder(): int
    {
        return 9;
    }

    /**
     * Whether the tab content can be loaded lazily over AJAX. The suggestion list is rendered inline,
     * so no AJAX round trip is needed.
     *
     * @return bool False, because the tab content is rendered with the page.
     */
    #[Override]
    public function canLoadAjax(): bool
    {
        return false;
    }

    /**
     * Whether the given individual has any suggestion worth showing in the tab.
     *
     * @param Individual $individual The individual whose tab visibility is probed.
     *
     * @return bool True when at least one non-terminal suggestion exists for the individual.
     */
    #[Override]
    public function hasTabContent(Individual $individual): bool
    {
        return $this->presenterForTree($individual->tree())->hasContent($individual->xref());
    }

    /**
     * Whether the tab should be shown greyed out. A greyed-out tab has no content but offers a way to
     * create some; this tab simply hides when it has nothing to show, so it is never greyed out.
     *
     * @param Individual $individual The individual whose tab state is probed.
     *
     * @return bool False, because the tab is shown only when it has content.
     */
    #[Override]
    public function isGrayedOut(Individual $individual): bool
    {
        return false;
    }

    /**
     * Renders the tab content for the given individual: the non-terminal suggestions read from the
     * tree-scoped match store, projected through the module's tab view.
     *
     * @param Individual $individual The individual whose suggestions are rendered.
     *
     * @return string The rendered tab HTML.
     */
    #[Override]
    public function getTabContent(Individual $individual): string
    {
        $suggestions = $this->presenterForTree($individual->tree())->suggestionsFor($individual->xref());

        return view($this->name() . '::tab', [
            'individual'  => $individual,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Builds the read-only suggestion presenter for the given tree. This is the test override seam:
     * a test subclass can return a presenter over an in-memory store instead of touching the disk.
     *
     * @param Tree $tree The tree whose suggestion presenter is requested.
     *
     * @return SuggestionTabPresenter The presenter reading the tree-scoped match store.
     */
    protected function presenterForTree(Tree $tree): SuggestionTabPresenter
    {
        return new SuggestionTabPresenter(MatchStoreFactory::forTree($tree));
    }
}
