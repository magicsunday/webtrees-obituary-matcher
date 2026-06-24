<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Aura\Router\Route;
use Aura\Router\RouterContainer;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWorklistHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Boots a real webtrees runtime, imports a fixture tree and drives the actual
 * {@see ObituaryMatcherModule::getMenu()} access decision of the
 * {@see \Fisharebest\Webtrees\Module\ModuleMenuInterface}: a manager receives a {@see Menu} entry
 * linking the tree-wide worklist route, while a non-manager receives null. The current user is driven
 * through the real {@see Auth} plumbing so the assertion exercises the genuine manager gate rather than
 * a stand-in.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryMatcherModule::class)]
final class ObituaryMatcherModuleMenuTest extends IntegrationTestCase
{
    /**
     * A manager receives a worklist menu entry whose link targets the worklist route.
     *
     * @return void
     */
    #[Test]
    public function getMenuReturnsAMenuForAManager(): void
    {
        $tree = $this->importFixtureTree("0 @I1@ INDI\n1 NAME Otto /Vorbild/\n");

        // route() needs the worklist route registered and a request bound in the container.
        $this->registerWorklistRoute();
        $this->bindRequest($tree);

        // The IntegrationTestCase setUp() logs in an administrator, who is a manager of every tree.
        $menu = (new ObituaryMatcherModule())->getMenu($tree);

        self::assertInstanceOf(Menu::class, $menu);
        self::assertStringContainsString('obituary-worklist', $menu->getLink());
    }

    /**
     * A non-manager receives no worklist menu entry.
     *
     * @return void
     */
    #[Test]
    public function getMenuReturnsNullForANonManager(): void
    {
        $tree = $this->importFixtureTree("0 @I1@ INDI\n1 NAME Otto /Vorbild/\n");

        // Replace the administrator from setUp() with a plain user who holds no manager role on the tree.
        $member = (new UserService())->create('member', 'Member', 'member@example.test', 'secret');
        Auth::login($member);

        self::assertNull((new ObituaryMatcherModule())->getMenu($tree));
    }

    /**
     * Registers the worklist route in a router container the same way webtrees' routing middleware would,
     * so the route() helper can resolve the menu link.
     *
     * @return void
     */
    private function registerWorklistRoute(): void
    {
        $routerContainer = new RouterContainer('/');
        $routerContainer->getMap()
            ->get(ObituaryWorklistHandler::ROUTE_NAME, ObituaryWorklistHandler::ROUTE_URL, ObituaryWorklistHandler::class);

        Registry::container()->set(RouterContainer::class, $routerContainer);
    }

    /**
     * Binds a GET request carrying the base URL into the container, exactly as webtrees' middleware would,
     * so the route() helper can build an absolute link.
     *
     * @param Tree $tree The tree attached as the request's `tree` attribute.
     *
     * @return void
     */
    private function bindRequest(Tree $tree): void
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(ObituaryWorklistHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest(RequestMethodInterface::METHOD_GET, 'https://webtrees.test/index.php')
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $tree);

        Registry::container()->set(ServerRequestInterface::class, $request);
    }
}
