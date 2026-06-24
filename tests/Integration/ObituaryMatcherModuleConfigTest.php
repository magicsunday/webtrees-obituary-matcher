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
use Fisharebest\Webtrees\Registry;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryControlPanelHandler;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Boots a real webtrees runtime and drives the
 * {@see \Fisharebest\Webtrees\Module\ModuleConfigInterface} contract of the
 * {@see ObituaryMatcherModule}: {@see ObituaryMatcherModule::getConfigLink()} must resolve to the
 * custom control-panel route rather than the trait default {@code action=Admin} page. The
 * control-panel route is registered in a router container the same way webtrees' routing middleware
 * would, so the route() helper can build the link.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryMatcherModule::class)]
final class ObituaryMatcherModuleConfigTest extends IntegrationTestCase
{
    /**
     * The config link targets the custom control-panel route, not the trait's `action=Admin` page.
     *
     * @return void
     */
    #[Test]
    public function getConfigLinkPointsAtTheControlPanelRoute(): void
    {
        // route() needs the control-panel route registered and a request bound in the container.
        $this->registerControlPanelRoute();
        $this->bindRequest();

        self::assertStringContainsString('obituary-matcher', (new ObituaryMatcherModule())->getConfigLink());
    }

    /**
     * Registers the control-panel route in a router container the same way webtrees' routing middleware
     * would, so the route() helper can resolve the config link.
     *
     * @return void
     */
    private function registerControlPanelRoute(): void
    {
        $routerContainer = new RouterContainer('/');
        $routerContainer->getMap()
            ->get(
                ObituaryControlPanelHandler::ROUTE_NAME,
                ObituaryControlPanelHandler::ROUTE_URL,
                ObituaryControlPanelHandler::class,
            );

        Registry::container()->set(RouterContainer::class, $routerContainer);
    }

    /**
     * Binds a GET request carrying the base URL into the container, exactly as webtrees' middleware would,
     * so the route() helper can build an absolute link.
     *
     * @return void
     */
    private function bindRequest(): void
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(ObituaryControlPanelHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest(RequestMethodInterface::METHOD_GET, 'https://webtrees.test/index.php')
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('route', $route);

        Registry::container()->set(ServerRequestInterface::class, $request);
    }
}
