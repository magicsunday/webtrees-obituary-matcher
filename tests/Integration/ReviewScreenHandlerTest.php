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
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ReviewScreenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

use function str_repeat;

/**
 * Integration tests for the review-screen route: GET renders one seeded row; an unknown or terminal
 * key 404s. The helpers stay local to this test (no second consumer yet) on top of the
 * {@see IntegrationTestCase} bootstrap, registering the theme, router and view namespace that the
 * full-layout {@see \Fisharebest\Webtrees\Http\ViewResponseTrait} render needs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ReviewScreenHandler::class)]
#[UsesClass(MatchSeeder::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(ReviewViewModel::class)]
#[UsesClass(TreePersonView::class)]
#[UsesClass(StoredMatchKey::class)]
final class ReviewScreenHandlerTest extends IntegrationTestCase
{
    use RemovesFlatTempStoreTrait;

    /**
     * The view namespace the handler renders under, registered locally for the test.
     */
    private const string MODULE_NAMESPACE = 'obituary-matcher-test';

    /**
     * The fixture tree built per test; the store is scoped to its numeric id.
     */
    private Tree $tree;

    /**
     * The temporary store directory created per test, removed in {@see tearDown()}.
     */
    private string $dir = '';

    /**
     * Boots the webtrees runtime, builds a one-person fixture tree and wires the theme, router and
     * the module view namespace the full-layout render relies on.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The full layouts/default render the handler emits needs a theme, a routing table (the
        // layout and menus call route()) and the module's view namespace — all normally wired by
        // webtrees' middleware and the module boot, none of which the bare IntegrationTestCase runs.
        Registry::container()->set(ModuleThemeInterface::class, new WebtreesTheme());

        $routerContainer = new RouterContainer('/');
        (new WebRoutes())->load($routerContainer->getMap());
        $routerContainer->getMap()
            ->get(ReviewScreenHandler::ROUTE_NAME, ReviewScreenHandler::ROUTE_URL, ReviewScreenHandler::class)
            ->allows(RequestMethodInterface::METHOD_POST);
        Registry::container()->set(RouterContainer::class, $routerContainer);

        (new ModuleService())->bootModules(new WebtreesTheme());

        View::registerNamespace(self::MODULE_NAMESPACE, __DIR__ . '/../../resources/views/');

        $this->tree = $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n1 BIRT\n2 DATE 4 SEP 1901\n2 PLAC Berlin\n"
        );

        $this->dir = $this->makeFlatStoreDir('om-review-');
    }

    /**
     * Removes the temp store directory and its rows regardless of how the test ended.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeFlatStoreDir();

        parent::tearDown();
    }

    /**
     * GET renders the review screen for a seeded pending row, including the source host and score.
     *
     * @return void
     */
    #[Test]
    public function getRendersSeededRow(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('trauer.example', (string) $response->getBody());
    }

    /**
     * An unknown key 404s.
     *
     * @return void
     */
    #[Test]
    public function getUnknownKey404s(): void
    {
        $request = $this->managerGetRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'I1', 'key' => str_repeat('0', 64)]
        );

        $this->expectException(HttpNotFoundException::class);

        $this->handler()->handle($request);
    }

    /**
     * A terminal (rejected) row 404s on the review route.
     *
     * @return void
     */
    #[Test]
    public function getTerminalRow404s(): void
    {
        $key = $this->seedPendingMatch('I1');
        $this->store()->markRejected('I1', 'https://trauer.example/I1', null);

        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $this->expectException(HttpNotFoundException::class);

        $this->handler()->handle($request);
    }

    /**
     * Builds the handler under test, scoped to this test's temp store via the seam override.
     *
     * @return ReviewScreenHandler The handler whose store points at the temp directory.
     */
    private function handler(): ReviewScreenHandler
    {
        return new class(self::MODULE_NAMESPACE, $this->dir) extends ReviewScreenHandler {
            /**
             * @param string $viewNamespace The view namespace the handler renders under.
             * @param string $storeDir      The temp store directory injected for the test.
             */
            public function __construct(string $viewNamespace, private readonly string $storeDir)
            {
                parent::__construct($viewNamespace);
            }

            protected function storeForTree(Tree $tree): MatchStore
            {
                return new FileMatchStore($this->storeDir);
            }
        };
    }

    /**
     * Returns the temp-directory store the seeded rows are written to.
     *
     * @return MatchStore The temp-directory store.
     */
    private function store(): MatchStore
    {
        return new FileMatchStore($this->dir);
    }

    /**
     * Upserts a pending row for the given candidate via the developer seeder and returns its row
     * key. The seeder fabricates the source URL deterministically from the XREF, so the key is
     * derived from that same URL — the test does not invent its own.
     *
     * @param string $xref The candidate identifier.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedPendingMatch(string $xref): string
    {
        $match = MatchSeeder::seed($this->store(), $xref, MatchStatus::Pending, 'strong', '2023-09-04');

        return StoredMatchKey::fromUrl($match->obituaryUrl);
    }

    /**
     * Builds a manager-authenticated GET request carrying the tree and the route attributes. The
     * logged-in administrator from {@see IntegrationTestCase::setUp()} is a manager of every tree.
     *
     * @param string                $routeName  The route name carried on the route attribute.
     * @param array<string, string> $attributes The route attributes (xref, key).
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function managerGetRequest(string $routeName, array $attributes): ServerRequestInterface
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name($routeName);

        $request = $factory
            ->createServerRequest(RequestMethodInterface::METHOD_GET, 'https://webtrees.test/index.php')
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $this->tree)
            ->withAttribute('user', Auth::user());

        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        Registry::container()->set(Tree::class, $this->tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }
}
