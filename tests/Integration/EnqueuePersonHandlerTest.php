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
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueuePersonHandler;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Integration scenarios for the {@see EnqueuePersonHandler}: a manager enqueues one chosen individual
 * from the obituary tab (#64). Each pins the discriminating outcome — the queued request read back off
 * the {@see RecordingJobTransport} double, the flash and
 * the PRG redirect — and the gate cases (non-manager, unconfigured, unknown xref) confirm nothing is
 * enqueued.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnqueuePersonHandler::class)]
#[UsesClass(EnqueueService::class)]
#[UsesClass(FinderConnection::class)]
#[UsesClass(ObituaryMatcherModule::class)]
final class EnqueuePersonHandlerTest extends AbstractEnqueueTestCase
{
    /**
     * The module instance under test, with a stable name so its preferences resolve.
     *
     * @var ObituaryMatcherModule
     */
    private ObituaryMatcherModule $module;

    /**
     * Boots the theme, the router (so route() resolves both the enqueue-person route and the core
     * IndividualPage redirect target) and the module, then wires a configured REST finder connection.
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
            ->get(EnqueuePersonHandler::ROUTE_NAME, EnqueuePersonHandler::ROUTE_URL, EnqueuePersonHandler::class)
            ->allows(RequestMethodInterface::METHOD_POST);
        Registry::container()->set(RouterContainer::class, $routerContainer);

        (new ModuleService())->bootModules(new WebtreesTheme());

        $this->module = new ObituaryMatcherModule();
        $this->module->setName('obituary-matcher-enq-test');

        DB::table('module')->insert([
            'module_name' => $this->module->name(),
            'status'      => 'enabled',
        ]);

        View::registerNamespace($this->module->name(), __DIR__ . '/../../resources/views/');

        // A configured REST connection so finderConnection() resolves for the happy-path tests. The
        // unconfigured case deletes these before its request.
        $this->module->setPreference('finder_transport', 'rest');
        $this->module->setPreference('finder_base_url', 'https://finder.example');
        $this->module->setPreference('finder_token', 'secret-token');
    }

    /**
     * A manager posting for a searchable individual enqueues exactly that person: one job carrying only
     * that xref, a success flash, and a PRG redirect back to the individual page.
     *
     * @return void
     */
    #[Test]
    public function aManagerEnqueuesTheChosenPerson(): void
    {
        $tree = $this->searchableTree('enq-person-happy', 1);

        $response = $this->handler()->handle($this->request($tree, 'I1', Auth::user()));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(['I1'], $this->queuedPersonIds($this->queuedJobIds()[0]));

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('success', $messages[0]->status);
    }

    /**
     * A non-manager principal is denied before any enqueue: the gate throws and no job is submitted.
     *
     * @return void
     */
    #[Test]
    public function aNonManagerIsDenied(): void
    {
        $tree   = $this->searchableTree('enq-person-denied', 1);
        $member = (new UserService())->create('enq-member', 'Member', 'enq-member@example.test', 'secret-pw-123456');

        $this->expectException(HttpAccessDeniedException::class);

        try {
            $this->handler()->handle($this->request($tree, 'I1', $member));
        } finally {
            self::assertSame([], $this->queuedJobIds());
        }
    }

    /**
     * With no finder connection configured, the enqueue is refused with a danger flash and nothing is
     * submitted — the person is never revealed to an unconfigured endpoint.
     *
     * @return void
     */
    #[Test]
    public function anUnconfiguredConnectionFlashesAndEnqueuesNothing(): void
    {
        $tree = $this->searchableTree('enq-person-unconfigured', 1);
        $this->module->setPreference('finder_transport', '');

        $response = $this->handler()->handle($this->request($tree, 'I1', Auth::user()));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame([], $this->queuedJobIds());

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('danger', $messages[0]->status);
    }

    /**
     * An unknown xref is a not-found individual: the access gate rejects it before any enqueue, so the
     * handler throws HttpNotFoundException (the standard webtrees "no such record" response) and nothing
     * is submitted.
     *
     * @return void
     */
    #[Test]
    public function anUnknownXrefThrowsNotFound(): void
    {
        $tree = $this->searchableTree('enq-person-unknown', 1);

        $this->expectException(HttpNotFoundException::class);

        try {
            $this->handler()->handle($this->request($tree, 'I999', Auth::user()));
        } finally {
            self::assertSame([], $this->queuedJobIds());
        }
    }

    /**
     * A person who already has a job in flight for this tree is not enqueued again: enqueueOne returns a
     * null-jobId summary, the handler flashes a warning, and no NEW job is submitted.
     *
     * @return void
     */
    #[Test]
    public function aPersonAlreadyInFlightFlashesAWarning(): void
    {
        $tree = $this->searchableTree('enq-person-inflight', 1);
        $this->seedInflightJob($tree->id(), ['I1']);

        $response = $this->handler()->handle($this->request($tree, 'I1', Auth::user()));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame([], $this->queuedJobIds());

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('warning', $messages[0]->status);
    }

    /**
     * A GET (any non-POST) never runs the mutating enqueue: it bounces back to the individual with no
     * job submitted.
     *
     * @return void
     */
    #[Test]
    public function aGetDoesNotEnqueue(): void
    {
        $tree = $this->searchableTree('enq-person-get', 1);

        $response = $this->handler()->handle(
            $this->request($tree, 'I1', Auth::user(), RequestMethodInterface::METHOD_GET)
        );

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame([], $this->queuedJobIds());
    }

    /**
     * Builds the handler with the finder-connection resolution left live (it reads the module prefs the
     * setUp wired) but the REST-ledger root and producer overridden onto the test's throwaway directory
     * and recording transport.
     *
     * @return EnqueuePersonHandler The test-wired handler.
     */
    private function handler(): EnqueuePersonHandler
    {
        return new class($this->module, $this->restPendingDir(), $this->enqueueService()) extends EnqueuePersonHandler {
            /**
             * @param ObituaryMatcherModule $module              The module instance.
             * @param string                $testRestPendingRoot The throwaway REST ledger root.
             * @param EnqueueService        $producer            The producer over the recording transport.
             */
            public function __construct(
                ObituaryMatcherModule $module,
                private readonly string $testRestPendingRoot,
                private readonly EnqueueService $producer,
            ) {
                parent::__construct($module);
            }

            protected function restPendingRoot(): string
            {
                return $this->testRestPendingRoot;
            }

            protected function enqueueService(FinderConnection $connection, string $restPendingRoot): EnqueueService
            {
                return $this->producer;
            }
        };
    }

    /**
     * Builds an attribute-carrying request for the given tree, xref and principal, mirroring the live
     * router: the {tree} + xref + user attributes the handler reads, plus the route/base scaffolding.
     *
     * @param Tree          $tree   The tree the request targets.
     * @param string        $xref   The individual xref.
     * @param UserInterface $user   The principal.
     * @param string        $method The HTTP method.
     *
     * @return ServerRequestInterface The assembled request.
     */
    private function request(
        Tree $tree,
        string $xref,
        UserInterface $user,
        string $method = RequestMethodInterface::METHOD_POST,
    ): ServerRequestInterface {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(EnqueuePersonHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest($method, 'https://webtrees.test/index.php')
            ->withParsedBody([])
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $tree)
            ->withAttribute('xref', $xref)
            ->withAttribute('user', $user);

        Registry::container()->set(Tree::class, $tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }

    /**
     * The throwaway REST in-flight ledger root under the isolated store root.
     *
     * @return string The REST-pending directory.
     */
    private function restPendingDir(): string
    {
        return $this->storeRoot . '/rest-pending';
    }
}
