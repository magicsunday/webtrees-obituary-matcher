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
use DomainException;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelPresenter;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelView;
use MagicSunday\ObituaryMatcher\Ui\JobStatusRowView;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueSummary;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryControlPanelHandler;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function count;
use function dirname;
use function http_build_query;
use function substr_count;

/**
 * Integration tests for the admin control-panel route: the admin gate, the GET render (prefilled
 * settings, the tree list, the empty-queue state), the STRICT settings save (both-or-neither, no
 * coercion, in-range), and the per-tree feeder trigger (unknown tree, and the load-bearing
 * persisted-settings cap). The handler builds the REAL {@see EnqueueService}
 * wiring; only its queue root is seamed onto the throwaway test queue, so the trigger path exercises
 * the real enqueue. The helpers reuse {@see AbstractEnqueueTestCase}'s throwaway queue + candidate
 * seeders; the handler's {@see ObituaryControlPanelHandler::handle()} is invoked directly so the tests
 * pass before the route is registered (Task 5).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ObituaryControlPanelHandler::class)]
#[UsesClass(ControlPanelPresenter::class)]
#[UsesClass(ControlPanelView::class)]
#[UsesClass(JobStatusRowView::class)]
final class ObituaryControlPanelHandlerTest extends AbstractEnqueueTestCase
{
    /**
     * The module instance under test, with a stable name so its preferences and view namespace resolve.
     */
    private ObituaryMatcherModule $module;

    /**
     * Boots the theme, the router (so {@see Route()} resolves the panel route) and the module view
     * namespace, then builds a module with a stable name for the preference reads/writes.
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
            ->get(ObituaryControlPanelHandler::ROUTE_NAME, ObituaryControlPanelHandler::ROUTE_URL, ObituaryControlPanelHandler::class)
            ->allows(RequestMethodInterface::METHOD_POST);
        Registry::container()->set(RouterContainer::class, $routerContainer);

        (new ModuleService())->bootModules(new WebtreesTheme());

        $this->module = new ObituaryMatcherModule();
        // The webtrees `module.module_name` column is VARCHAR(32); a longer name truncates on MySQL
        // (SQLite does not enforce the length, so an over-long name passes locally but reds CI).
        $this->module->setName('obituary-matcher-cp-test');

        // module_setting.module_name carries a foreign key onto the module table, so a settings write
        // needs a matching module row. The live install seeds it on registration; seed it here.
        DB::table('module')->insert([
            'module_name' => $this->module->name(),
            'status'      => 'enabled',
        ]);

        View::registerNamespace($this->module->name(), __DIR__ . '/../../resources/views/');
    }

    /**
     * A genuinely non-admin member is denied: the setUp admin is replaced by a freshly created member
     * holding no administrator preference, so {@see Auth::isAdmin()} is false and the gate throws.
     *
     * @return void
     */
    #[Test]
    public function nonAdminIsDenied(): void
    {
        $member = (new UserService())->create('member', 'Member', 'member@example.test', 'secret');
        Auth::login($member);

        $this->expectException(HttpAccessDeniedException::class);

        $this->handler()->handle($this->panelRequest(RequestMethodInterface::METHOD_GET));
    }

    /**
     * A GET render carries the prefilled default min_age, the offered tree and the empty-queue state.
     *
     * @return void
     */
    #[Test]
    public function getRendersSettingsTreesAndEmptyJobs(): void
    {
        $this->searchableTree('panel-get', 1);

        $html = (string) $this->handler()->handle($this->panelRequest(RequestMethodInterface::METHOD_GET))->getBody();

        // The default min_age (90) is prefilled.
        self::assertStringContainsString('90', $html);
        // The offered tree appears (the trigger form is rendered per tree).
        self::assertStringContainsString('panel-get', $html);
        // The empty-queue state renders.
        self::assertStringContainsString('No searches run yet.', $html);
        // BOTH POST forms (save + the per-tree trigger) carry a CSRF token, or the live POST
        // would be bounced by webtrees' CheckCsrf middleware (the direct-handle() tests bypass it).
        self::assertGreaterThanOrEqual(2, substr_count($html, 'name="_csrf"'));
    }

    /**
     * A GET render projects a multi-state queue: a done job and a running job each render their state
     * label badge, the done job's non-empty counts render as generic `key=value` pairs and the running
     * job's null finishedAt renders the `—` placeholder. This pins the Task 6 template completion —
     * generic counts, the per-state labels and the null-finishedAt placeholder.
     *
     * @return void
     */
    #[Test]
    public function getRendersJobStatesGenericCountsAndNullFinishedPlaceholder(): void
    {
        $this->searchableTree('panel-states', 1);

        // A terminal done job carrying a counts map and a finish timestamp, plus a non-terminal running
        // job whose finishedAt is null — seeded straight into the handler's seamed queue root.
        $this->seedStatusJob(
            'job-0002-done',
            JobState::Done,
            ['counts' => ['candidates' => 4, 'notices' => 2], 'finishedAt' => '2026-06-23T10:15:35+00:00'],
        );
        $this->seedStatusJob('job-0001-run', JobState::Running, []);

        $html = (string) $this->handler()->handle($this->panelRequest(RequestMethodInterface::METHOD_GET))->getBody();

        // Each seeded state renders its i18n label badge.
        self::assertStringContainsString('Done', $html);
        self::assertStringContainsString('Running', $html);
        // The done job's counts render generically as key=value pairs (keys are not hardcoded in the view).
        self::assertStringContainsString('candidates=4', $html);
        self::assertStringContainsString('notices=2', $html);
        // The non-terminal running job's null finishedAt renders the placeholder.
        self::assertStringContainsString('—', $html);
    }

    /**
     * A valid save in range persists BOTH settings.
     *
     * @return void
     */
    #[Test]
    public function saveValidPersistsBothSettings(): void
    {
        $this->handler()->handle($this->panelPost(['action' => 'save', 'min_age' => '80', 'limit' => '25']));

        self::assertSame('80', $this->module->getPreference('min_age'));
        self::assertSame('25', $this->module->getPreference('limit'));
    }

    /**
     * An out-of-bounds value persists NEITHER setting (min_age 999 over the 120 ceiling, limit 0 below
     * the 1 floor): both pre-set values are unchanged.
     *
     * @return void
     */
    #[Test]
    public function saveOutOfBoundsPersistsNeither(): void
    {
        $this->module->setPreference('min_age', '90');
        $this->module->setPreference('limit', '50');

        $this->handler()->handle($this->panelPost(['action' => 'save', 'min_age' => '999', 'limit' => '0']));

        self::assertSame('90', $this->module->getPreference('min_age'));
        self::assertSame('50', $this->module->getPreference('limit'));
    }

    /**
     * One valid + one invalid value persists NEITHER (no partial save): the valid min_age is NOT written
     * because the limit failed validation.
     *
     * @return void
     */
    #[Test]
    public function saveOneValidOneInvalidPersistsNeither(): void
    {
        $this->module->setPreference('min_age', '90');
        $this->module->setPreference('limit', '50');

        $this->handler()->handle($this->panelPost(['action' => 'save', 'min_age' => '50', 'limit' => '0']));

        self::assertSame('90', $this->module->getPreference('min_age'));
        self::assertSame('50', $this->module->getPreference('limit'));
    }

    /**
     * A non-integer string is rejected, not coerced: "90abc" never becomes a saved value.
     *
     * @return void
     */
    #[Test]
    public function saveNonIntegerStringIsRejectedNotCoerced(): void
    {
        $this->module->setPreference('min_age', '90');

        $this->handler()->handle($this->panelPost(['action' => 'save', 'min_age' => '90abc', 'limit' => '50']));

        self::assertSame('90', $this->module->getPreference('min_age'));
    }

    /**
     * An unknown tree id flashes and enqueues nothing: the handler PRG-redirects and the queue stays
     * empty.
     *
     * @return void
     */
    #[Test]
    public function triggerUnknownTreeFlashesAndDoesNotEnqueue(): void
    {
        $response = $this->handler()->handle($this->panelPost(['action' => 'trigger', 'tree' => '99999']));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(0, $this->queuedJobCount());
    }

    /**
     * The load-bearing trigger test: a valid tree with five eligible candidates, the persisted limit
     * pinned to 3, enqueues exactly one job whose candidate count honours the PERSISTED limit (3), not
     * the default 50 — proving the handler reads the saved settings rather than a hardcoded default.
     *
     * @return void
     */
    #[Test]
    public function triggerValidTreeUsesPersistedSettingsAndEnqueues(): void
    {
        $this->module->setPreference('min_age', '70');
        $this->module->setPreference('limit', '3');

        // Five eligible candidates (born 1930, far over age 70, no death date): with limit 3 the producer
        // must cap the enqueued set to three.
        $tree = $this->searchableTree('panel-trigger', 5);

        $response = $this->handler()->handle($this->panelPost(['action' => 'trigger', 'tree' => (string) $tree->id()]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(1, $this->queuedJobCount());

        // The enqueued request honoured the persisted limit (3), not the default 50.
        self::assertLessThanOrEqual(3, $this->enqueuedCandidateCount());
    }

    /**
     * A DomainException from the producer (the tree vanished between the handler's resolve and the
     * producer's own re-resolve — a TOCTOU race) flashes and PRG-redirects rather than escaping
     * handle() as an unhandled 500: the always-PRG-redirect contract holds on the producer's
     * DomainException, which is NOT a RuntimeException.
     *
     * @return void
     */
    #[Test]
    public function triggerProducerDomainExceptionFlashesAndRedirects(): void
    {
        $tree = $this->searchableTree('panel-toctou', 1);

        $response = $this->throwingHandler(new DomainException('vanished'))
            ->handle($this->panelPost(['action' => 'trigger', 'tree' => (string) $tree->id()]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(0, $this->queuedJobCount());
    }

    /**
     * A RuntimeException from the producer (a queue/filesystem failure) flashes and PRG-redirects
     * rather than escaping handle() as an unhandled 500.
     *
     * @return void
     */
    #[Test]
    public function triggerProducerRuntimeExceptionFlashesAndRedirects(): void
    {
        $tree = $this->searchableTree('panel-queue-fail', 1);

        $response = $this->throwingHandler(new RuntimeException('queue write failed'))
            ->handle($this->panelPost(['action' => 'trigger', 'tree' => (string) $tree->id()]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(0, $this->queuedJobCount());
    }

    /**
     * Builds the handler under test, seamed onto the throwaway test queue root so the trigger path runs
     * the REAL EnqueueService wiring against the isolated queue.
     *
     * @return ObituaryControlPanelHandler The handler whose queue root points at the temp dir.
     */
    private function handler(): ObituaryControlPanelHandler
    {
        return new class($this->module, $this->queueRoot) extends ObituaryControlPanelHandler {
            /**
             * @param ObituaryMatcherModule $module    The module instance.
             * @param string                $queueRoot The throwaway queue root injected for the test.
             */
            public function __construct(ObituaryMatcherModule $module, private readonly string $queueRoot)
            {
                parent::__construct($module);
            }

            protected function queueRoot(): string
            {
                return $this->queueRoot;
            }
        };
    }

    /**
     * Builds a handler whose producer always throws the given exception from enqueue(), so the
     * trigger path's exception handling (always PRG-redirect) can be exercised without a real
     * producer failure. The queue root is still seamed onto the throwaway dir.
     *
     * @param Throwable $exception The exception the seamed producer throws from enqueue().
     *
     * @return ObituaryControlPanelHandler The handler whose producer throws.
     */
    private function throwingHandler(Throwable $exception): ObituaryControlPanelHandler
    {
        return new class($this->module, $this->queueRoot, $exception) extends ObituaryControlPanelHandler {
            /**
             * @param ObituaryMatcherModule $module    The module instance.
             * @param string                $queueRoot The throwaway queue root injected for the test.
             * @param Throwable             $exception The exception the seamed producer throws.
             */
            public function __construct(
                ObituaryMatcherModule $module,
                private readonly string $queueRoot,
                private readonly Throwable $exception,
            ) {
                parent::__construct($module);
            }

            protected function queueRoot(): string
            {
                return $this->queueRoot;
            }

            protected function enqueueService(QueuePaths $paths): EnqueueService
            {
                $exception = $this->exception;

                return new class($paths, $exception) extends EnqueueService {
                    /**
                     * @param QueuePaths $paths     The queue path builder.
                     * @param Throwable  $exception The exception to throw from enqueue().
                     */
                    public function __construct(QueuePaths $paths, private readonly Throwable $exception)
                    {
                        parent::__construct(
                            $paths,
                            new QueueClient($paths),
                            new FeederRequestReader($paths, 5_242_880),
                            new CandidateRepository(),
                            new FeederRequestFactory(new QueryGenerator()),
                            new UrlHostNormalizer(),
                            new TreeService(new GedcomImportService()),
                        );
                    }

                    public function enqueue(int $treeId, int $limit, int $minAge, string $locale, ?int $referenceYear = null): EnqueueSummary
                    {
                        throw $this->exception;
                    }
                };
            }
        };
    }

    /**
     * Builds a GET request for the panel route authenticated as the current user.
     *
     * @param string $method The HTTP method.
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function panelRequest(string $method): ServerRequestInterface
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(ObituaryControlPanelHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest($method, 'https://webtrees.test/index.php')
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('user', Auth::user());

        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }

    /**
     * Builds a POST request for the panel route carrying the given parsed body, authenticated as the
     * setUp administrator.
     *
     * @param array<string, string> $body The form body.
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function panelPost(array $body): ServerRequestInterface
    {
        return $this->panelRequest(RequestMethodInterface::METHOD_POST)
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->bodyStream(http_build_query($body)));
    }

    /**
     * Wraps a raw string into a PSR-7 body stream.
     *
     * @param string $contents The raw body contents.
     *
     * @return StreamInterface The body stream.
     */
    private function bodyStream(string $contents): StreamInterface
    {
        $factory = Registry::container()->get(StreamFactoryInterface::class);
        self::assertInstanceOf(StreamFactoryInterface::class, $factory);

        return $factory->createStream($contents);
    }

    /**
     * Seeds a job's status.json into the handler's seamed queue root, so the GET render's recent-jobs
     * projection ({@see QueueClient::recentJobs()}) hydrates and renders it. Mirrors how
     * QueueClientTest seeds a terminal status: write the status.json into the job's state directory.
     *
     * @param string               $jobId The job identifier (also the directory name).
     * @param JobState             $state The state directory to seed the job into.
     * @param array<string, mixed> $extra Extra status.json fields (e.g. counts, finishedAt) merged in.
     *
     * @return void
     */
    private function seedStatusJob(string $jobId, JobState $state, array $extra): void
    {
        $path = $this->paths()->stateDir($state, $jobId) . '/status.json';

        AtomicFile::ensureDirectory(dirname($path));
        AtomicFile::writeJson($path, ['state' => $state->value] + $extra);
    }

    /**
     * Counts the real queued job directories (excluding dot + temp entries).
     *
     * @return int The number of queued jobs.
     */
    private function queuedJobCount(): int
    {
        return count($this->queuedJobIds());
    }

    /**
     * The candidate count of the single queued job's request.json.
     *
     * @return int The number of candidates written into the queued request.
     */
    private function enqueuedCandidateCount(): int
    {
        $jobIds = $this->queuedJobIds();
        self::assertCount(1, $jobIds);

        $path = $this->paths()->stateRoot(JobState::Queued->value) . '/' . $jobIds[0] . '/request.json';
        $data = AtomicFile::readJsonCapped($path, 5_242_880);

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $data['candidates'];

        return count($candidates);
    }
}
