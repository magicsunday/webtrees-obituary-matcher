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
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\WorklistPresenter;
use MagicSunday\ObituaryMatcher\Ui\WorklistRowView;
use MagicSunday\ObituaryMatcher\Ui\WorklistView;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWorklistHandler;
use MagicSunday\ObituaryMatcher\Webtrees\ReviewScreenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Integration tests for the tree-wide worklist route: a manager GET renders every stored row across
 * statuses, a stale-person row is skipped from the list and the counts, a terminal row carries no
 * per-item review link, and an HTML name is neutralised at the boundary. A non-manager is denied. The
 * helpers mirror {@see ReviewScreenHandlerTest}'s harness (real tree, temp file store, route + theme +
 * view namespace wiring), invoking the handler's {@see ObituaryWorklistHandler::handle()} directly so
 * the tests pass before the route is registered (Task 5).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryWorklistHandler::class)]
#[UsesClass(MatchSeeder::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(StoredMatchKey::class)]
#[UsesClass(WriteBack::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(WorklistPresenter::class)]
#[UsesClass(WorklistView::class)]
#[UsesClass(WorklistRowView::class)]
#[UsesClass(BandKey::class)]
#[UsesClass(ObituaryDateFormatter::class)]
#[UsesClass(SourceLink::class)]
final class ObituaryWorklistHandlerTest extends IntegrationTestCase
{
    use RemovesFlatTempStoreTrait;

    /* jscpd:ignore-start - the field set and the theme/router/view-namespace harness wiring converge with ReviewScreenHandlerTest's by necessity (the sibling handler harness this test mirrors) */

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
     * Boots the webtrees runtime, builds the fixture tree and wires the theme, router and the module
     * view namespace the full-layout render relies on.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Registry::container()->set(ModuleThemeInterface::class, new WebtreesTheme());

        $routerContainer = new RouterContainer('/');
        (new WebRoutes())->load($routerContainer->getMap());
        $routerContainer->getMap()
            ->get(ReviewScreenHandler::ROUTE_NAME, ReviewScreenHandler::ROUTE_URL, ReviewScreenHandler::class)
            ->allows(RequestMethodInterface::METHOD_POST);

        /* jscpd:ignore-end */

        $routerContainer->getMap()
            ->get(ObituaryWorklistHandler::ROUTE_NAME, ObituaryWorklistHandler::ROUTE_URL, ObituaryWorklistHandler::class);
        Registry::container()->set(RouterContainer::class, $routerContainer);

        (new ModuleService())->bootModules(new WebtreesTheme());

        View::registerNamespace(self::MODULE_NAMESPACE, __DIR__ . '/../../resources/views/');

        $this->tree = $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n1 BIRT\n2 DATE 4 SEP 1901\n2 PLAC Berlin\n1 DEAT\n2 DATE 25 JAN 1932\n"
            . "0 @I2@ INDI\n1 NAME Emma /Ortlos/\n1 SEX F\n1 BIRT\n2 PLAC Hamburg\n"
            . "0 @I3@ INDI\n1 NAME Karl /Beispiel/\n1 SEX M\n"
            . "0 @IHTML@ INDI\n1 NAME <b>Max</b> /Mustermann/\n1 SEX M\n"
        );

        $this->dir = $this->makeFlatStoreDir('om-worklist-');
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
     * A non-manager (here a guest) is denied even with seeded rows, exercising the real
     * {@see Auth::isManager()} gate rather than the admin happy path.
     *
     * @return void
     */
    #[Test]
    public function nonManagerIsDenied(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $this->expectException(HttpAccessDeniedException::class);

        $this->handler()->handle($this->worklistRequest(new GuestUser()));
    }

    /**
     * A manager GET over a mixed store renders every surviving row and the total count: a pending, a
     * confirmed and a rejected row all reach the body, and the total reflects the three survivors.
     *
     * @return void
     */
    #[Test]
    public function rendersRowsAndCountsForAMixedStore(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->seedConfirmed('I2');
        $this->seedRejected('I3');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('I1', $html);
        self::assertStringContainsString('I2', $html);
        self::assertStringContainsString('I3', $html);
        // The rendered counts header reflects exactly three surviving rows.
        self::assertStringContainsString('Total: 3', $html);
    }

    /**
     * A row whose personId resolves to no individual is skipped from the list and from the counts: the
     * ghost XREF never reaches the body and the total reflects only the one surviving row.
     *
     * @return void
     */
    #[Test]
    public function stalePersonRowIsSkippedFromListAndCounts(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        MatchSeeder::seed($this->store(), 'IGHOST', MatchStatus::Pending, 'weak', '2023-09-04');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('I1', $html);
        self::assertStringNotContainsString('IGHOST', $html);
        // The total reflects the single surviving row, not both seeded rows.
        self::assertStringContainsString('Total: 1', $html);
    }

    /**
     * A terminal (confirmed) row renders no per-item review link: the worklist row carries the
     * individual link but no `obituary-review/<xref>` review affordance.
     *
     * @return void
     */
    #[Test]
    public function terminalRowRendersNoReviewLink(): void
    {
        $this->seedConfirmed('I1');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('I1', $html);
        self::assertStringNotContainsString('obituary-review/I1', $html);
    }

    /**
     * An individual whose fullName carries markup is neutralised at the boundary: the live `<b>` markup
     * is stripped before the entry is built and the plain text is e()-escaped, so no live `<b>Max</b>`
     * reaches the body.
     *
     * @return void
     */
    #[Test]
    public function htmlInNameIsNeutralisedAtTheBoundary(): void
    {
        MatchSeeder::seed($this->store(), 'IHTML', MatchStatus::Pending, 'strong', '2023-09-04');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('IHTML', $html);
        // The live markup is stripped at the boundary and the plain text e()-escaped: no live tag leaks.
        self::assertStringNotContainsString('<b>Max</b>', $html);
        // The plain name text SURVIVES the strip — distinguishes "stripped + escaped" from "dropped entirely".
        self::assertStringContainsString('Mustermann', $html);
    }

    /**
     * Seeds a confirmed row for the given candidate: a pending row is upserted via the seeder, then the
     * store transitions it to Confirmed with a synthetic write-back (mirroring a completed confirm).
     *
     * @param string $xref The candidate identifier.
     *
     * @return void
     */
    private function seedConfirmed(string $xref): void
    {
        $match = MatchSeeder::seed($this->store(), $xref, MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed($xref, $match->obituaryUrl, new WriteBack('@F1@', '@S1@', true));
    }

    /**
     * Seeds a rejected row for the given candidate: a pending row is upserted via the seeder, then the
     * store transitions it to Rejected (mirroring a reviewer rejection).
     *
     * @param string $xref The candidate identifier.
     *
     * @return void
     */
    private function seedRejected(string $xref): void
    {
        $match = MatchSeeder::seed($this->store(), $xref, MatchStatus::Pending, 'weak', '2023-09-04');
        $this->store()->markRejected($xref, $match->obituaryUrl, null);
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
     * Builds the handler under test, scoped to this test's temp store via the seam override.
     *
     * @return ObituaryWorklistHandler The handler whose store points at the temp directory.
     */
    private function handler(): ObituaryWorklistHandler
    {
        return new class(self::MODULE_NAMESPACE, $this->dir) extends ObituaryWorklistHandler {
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
     * Builds a GET request for the worklist route authenticated as the given user, carrying the tree
     * and the route attribute exactly as webtrees' middleware would.
     *
     * @param UserInterface $user The user attached as the request's `user` attribute.
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function worklistRequest(UserInterface $user): ServerRequestInterface
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(ObituaryWorklistHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest(RequestMethodInterface::METHOD_GET, 'https://webtrees.test/index.php')
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $this->tree)
            ->withAttribute('user', $user);

        Registry::container()->set(Tree::class, $this->tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }
}
