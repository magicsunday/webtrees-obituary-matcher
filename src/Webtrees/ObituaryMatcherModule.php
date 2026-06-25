<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Ui\SuggestionTabPresenter;
use Override;

use function realpath;
use function route;
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
class ObituaryMatcherModule extends AbstractModule implements ModuleConfigInterface, ModuleCustomInterface, ModuleMenuInterface, ModuleTabInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;
    use ModuleMenuTrait;
    use ModuleConfigTrait;

    /**
     * @var array<int, SuggestionTabPresenter> Per-request presenter cache keyed by tree id.
     */
    private array $presenters = [];

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
     * Bootstrap the module by registering its view namespace (so the tab and review templates resolve
     * under the module name) and the review-screen route. The namespace registration is guarded by
     * {@see realpath()} so a missing resources directory leaves the namespace unregistered instead of
     * pointing it at a non-existent path. The route is registered as a {@see ReviewScreenHandler}
     * instance bound to the module's own view namespace and allows POST for the Task 5 decision
     * dispatch. The tree-wide worklist GET route ({@see ObituaryWorklistHandler}) is registered
     * alongside it, exposed through the manager-only main-menu entry in {@see self::getMenu()}. The
     * admin control-panel route ({@see ObituaryControlPanelHandler}) is registered too and allows POST
     * for its save/trigger actions; it is surfaced in the control panel via {@see self::getConfigLink()}.
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

        $routeMap = Registry::routeFactory()->routeMap();

        $routeMap
            ->get(ReviewScreenHandler::ROUTE_NAME, ReviewScreenHandler::ROUTE_URL, new ReviewScreenHandler($this->name()))
            ->allows(RequestMethodInterface::METHOD_POST);

        $routeMap
            ->get(ObituaryWorklistHandler::ROUTE_NAME, ObituaryWorklistHandler::ROUTE_URL, new ObituaryWorklistHandler($this->name()))
            ->allows(RequestMethodInterface::METHOD_POST);

        $routeMap
            ->get(ObituaryControlPanelHandler::ROUTE_NAME, ObituaryControlPanelHandler::ROUTE_URL, new ObituaryControlPanelHandler($this))
            ->allows(RequestMethodInterface::METHOD_POST);
    }

    /**
     * Returns the URL of the module's admin control panel.
     *
     * @return string The control-panel route URL.
     */
    #[Override]
    public function getConfigLink(): string
    {
        return route(ObituaryControlPanelHandler::ROUTE_NAME);
    }

    /**
     * The manager-only main-menu entry linking the tree-wide obituary worklist, or null when the
     * current user is not a manager of the given tree. The label reuses the tab's `Death notices`
     * key so the menu and tab read consistently.
     *
     * @param Tree $tree The tree whose worklist the menu entry links.
     *
     * @return Menu|null The worklist menu entry, or null when the user is not a manager.
     */
    #[Override]
    public function getMenu(Tree $tree): ?Menu
    {
        if (!Auth::isManager($tree)) {
            return null;
        }

        return new Menu(
            I18N::translate('Death notices'),
            route(ObituaryWorklistHandler::ROUTE_NAME, ['tree' => $tree->name()]),
            'menu-obituary-worklist',
        );
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
        return I18N::translate('Death notices');
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
        return $this->presenters[$tree->id()] ??= new SuggestionTabPresenter(MatchStoreFactory::forTree($tree));
    }
}
