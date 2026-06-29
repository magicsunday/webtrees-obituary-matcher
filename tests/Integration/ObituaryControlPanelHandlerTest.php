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
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use LogicException;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\CapabilitiesProbeResult;
use MagicSunday\ObituaryMatcher\Queue\CappedJsonBodyReader;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\FileJobTransport;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilities;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilitiesProbe;
use MagicSunday\ObituaryMatcher\Queue\FinderPortal;
use MagicSunday\ObituaryMatcher\Queue\JobState;
use MagicSunday\ObituaryMatcher\Queue\ProbeStatus;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;
use MagicSunday\ObituaryMatcher\Test\Queue\ScriptablePsr18Client;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelPresenter;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelView;
use MagicSunday\ObituaryMatcher\Ui\FinderConnectionView;
use MagicSunday\ObituaryMatcher\Ui\JobStatusRowView;
use MagicSunday\ObituaryMatcher\Ui\ProbeReadoutView;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueService;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueSummary;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryControlPanelHandler;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryMatcherModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function count;
use function dirname;
use function http_build_query;
use function json_encode;
use function substr_count;

use const JSON_THROW_ON_ERROR;

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
#[UsesClass(FinderConnection::class)]
#[UsesClass(FinderConnectionView::class)]
#[UsesClass(ProbeReadoutView::class)]
#[UsesClass(CapabilitiesProbeResult::class)]
#[UsesClass(ProbeStatus::class)]
#[UsesClass(FinderCapabilitiesProbe::class)]
#[UsesClass(FinderCapabilities::class)]
#[UsesClass(FinderPortal::class)]
#[UsesClass(CappedJsonBodyReader::class)]
#[UsesClass(QueueLimits::class)]
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
        // The done job's counts render generically as key=value pairs (keys are not hardcoded in the view),
        // joined with a ', ' separator so two pairs read as "candidates=4, notices=2" and never run
        // together as "4notices".
        self::assertStringContainsString('candidates=4', $html);
        self::assertStringContainsString('notices=2', $html);
        self::assertStringContainsString('candidates=4, notices=2', $html);
        self::assertStringNotContainsString('4notices', $html);
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
     * A generic Throwable from the producer (NOT a DomainException or RuntimeException — e.g. a
     * LogicException) still flashes and PRG-redirects rather than escaping handle() as an unhandled 500:
     * the final catch arm was widened to Throwable to guarantee the always-PRG-redirect contract.
     *
     * @return void
     */
    #[Test]
    public function triggerProducerGenericThrowableFlashesAndRedirects(): void
    {
        $tree = $this->searchableTree('panel-generic-throwable', 1);

        $response = $this->throwingHandler(new LogicException('unexpected producer failure'))
            ->handle($this->panelPost(['action' => 'trigger', 'tree' => (string) $tree->id()]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame(0, $this->queuedJobCount());
    }

    /**
     * A pathologically long digit string for min_age (well over the length cap and far over the 120
     * ceiling) is rejected, not persisted: the strict parser's length guard short-circuits before the
     * (int) cast would saturate to PHP_INT_MAX.
     *
     * @return void
     */
    #[Test]
    public function savePathologicallyLongDigitStringIsRejected(): void
    {
        $this->module->setPreference('min_age', '90');
        $this->module->setPreference('limit', '50');

        $this->handler()->handle(
            $this->panelPost(['action' => 'save', 'min_age' => '9999999999', 'limit' => '50'])
        );

        self::assertSame('90', $this->module->getPreference('min_age'));
        self::assertSame('50', $this->module->getPreference('limit'));
    }

    /**
     * With no finder preferences set (the #58 config UI has not run), the connection the enqueue path
     * builds is the default file-drop transport, so the control panel behaves byte-for-byte as before.
     *
     * @return void
     */
    #[Test]
    public function theDefaultFinderPreferencesSelectTheFileTransport(): void
    {
        $handler = new class($this->module) extends ObituaryControlPanelHandler {
            /**
             * Exposes the persisted finder connection for assertion.
             *
             * @return FinderConnection The connection the module preferences select.
             */
            public function exposedConnection(): FinderConnection
            {
                return $this->finderConnection();
            }
        };

        self::assertSame('file', $handler->exposedConnection()->transport());
    }

    /**
     * Once `finder_transport=rest` (and the base URL) are persisted, the enqueue path builds the REST
     * connection — the #58 plumbing the control panel honours once the config UI sets it.
     *
     * @return void
     */
    #[Test]
    public function theRestFinderPreferenceSelectsTheRestTransport(): void
    {
        $this->module->setPreference('finder_transport', 'rest');
        $this->module->setPreference('finder_base_url', 'http://finder:8080');

        $handler = new class($this->module) extends ObituaryControlPanelHandler {
            /**
             * Exposes the persisted finder connection for assertion.
             *
             * @return FinderConnection The connection the module preferences select.
             */
            public function exposedConnection(): FinderConnection
            {
                return $this->finderConnection();
            }
        };

        $connection = $handler->exposedConnection();

        self::assertSame('rest', $connection->transport());
        self::assertSame('http://finder:8080', $connection->baseUrl());
    }

    /**
     * The save-finder action persists a valid REST connection: the transport, the base URL and the
     * token are all written and the handler PRG-redirects.
     *
     * @return void
     */
    #[Test]
    public function saveFinderPersistsAValidRestConnection(): void
    {
        $response = $this->handler()->handle($this->panelPost([
            'action'    => 'save-finder',
            'transport' => 'rest',
            'base_url'  => 'https://finder.example',
            'token'     => 'secret',
        ]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertSame('rest', $this->module->getPreference('finder_transport'));
        self::assertSame('https://finder.example', $this->module->getPreference('finder_base_url'));
        self::assertSame('secret', $this->module->getPreference('finder_token'));
    }

    /**
     * The save-finder action rejects an invalid base URL strictly: NEITHER the transport, the base URL
     * nor the token is written (both-or-neither), and the handler still PRG-redirects.
     *
     * @return void
     */
    #[Test]
    public function saveFinderRejectsAnInvalidBaseUrlAndWritesNothing(): void
    {
        $response = $this->handler()->handle($this->panelPost([
            'action'    => 'save-finder',
            'transport' => 'rest',
            'base_url'  => 'ftp://x',
            'token'     => 'secret',
        ]));

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        // Nothing persisted: every preference resolves to its unset default.
        self::assertSame('', $this->module->getPreference('finder_transport'));
        self::assertSame('', $this->module->getPreference('finder_base_url'));
        self::assertSame('', $this->module->getPreference('finder_token'));
    }

    /**
     * A blank token on a valid REST save leaves the existing token untouched (a blank field is "keep",
     * not "clear").
     *
     * @return void
     */
    #[Test]
    public function saveFinderBlankTokenKeepsTheExistingToken(): void
    {
        $this->module->setPreference('finder_token', 'old');

        $this->handler()->handle($this->panelPost([
            'action'    => 'save-finder',
            'transport' => 'rest',
            'base_url'  => 'https://finder.example',
            'token'     => '',
        ]));

        self::assertSame('rest', $this->module->getPreference('finder_transport'));
        self::assertSame('https://finder.example', $this->module->getPreference('finder_base_url'));
        self::assertSame('old', $this->module->getPreference('finder_token'));
    }

    /**
     * The explicit remove-token flag clears the stored token on a valid REST save.
     *
     * @return void
     */
    #[Test]
    public function saveFinderRemoveTokenClearsIt(): void
    {
        $this->module->setPreference('finder_token', 'old');

        $this->handler()->handle($this->panelPost([
            'action'       => 'save-finder',
            'transport'    => 'rest',
            'base_url'     => 'https://finder.example',
            'token'        => '',
            'remove_token' => '1',
        ]));

        self::assertSame('rest', $this->module->getPreference('finder_transport'));
        self::assertSame('', $this->module->getPreference('finder_token'));
    }

    /**
     * Selecting the file transport persists `finder_transport=file` but leaves a previously stored REST
     * base URL and token intact, so a file↔rest toggle keeps the REST data.
     *
     * @return void
     */
    #[Test]
    public function saveFinderFileKeepsStoredRestData(): void
    {
        $this->module->setPreference('finder_base_url', 'https://finder.example');
        $this->module->setPreference('finder_token', 'old');

        $this->handler()->handle($this->panelPost([
            'action'    => 'save-finder',
            'transport' => 'file',
        ]));

        self::assertSame('file', $this->module->getPreference('finder_transport'));
        self::assertSame('https://finder.example', $this->module->getPreference('finder_base_url'));
        self::assertSame('old', $this->module->getPreference('finder_token'));
    }

    /**
     * The `test` action probes a valid REST finder and re-renders (NOT redirects) with a reachable
     * readout carrying the advertised finder id: the scripted client answers the capabilities request
     * with a valid document, and the captured finder view carries the mapped readout.
     *
     * @return void
     */
    #[Test]
    public function testActionRendersAReachableReadout(): void
    {
        $handler = $this->capturingHandler([
            static fn (): ResponseInterface => self::jsonResponse(self::validCapabilitiesBody()),
        ]);

        $response = $handler->handle($this->panelPost([
            'action'    => 'test',
            'transport' => 'rest',
            'base_url'  => 'https://finder.example',
        ]));

        // The action re-renders rather than PRG-redirecting (a 302 would signal the wrong contract).
        self::assertNotSame(StatusCodeInterface::STATUS_FOUND, $response->getStatusCode());
        self::assertNotNull($handler->capturedProbe);
        self::assertSame('reachable', $handler->capturedProbe->statusKey);
        self::assertSame('finder-x', $handler->capturedProbe->finderId);
    }

    /**
     * In file mode the `test` action does NOT probe at all: the readout is not-applicable, the probe seam
     * was never invoked and the injected client recorded zero sent requests.
     *
     * @return void
     */
    #[Test]
    public function testActionInFileModeIsNotApplicableAndDoesNotProbe(): void
    {
        $handler = $this->capturingHandler([]);

        $handler->handle($this->panelPost([
            'action'    => 'test',
            'transport' => 'file',
        ]));

        self::assertNotNull($handler->capturedProbe);
        self::assertSame('not-applicable', $handler->capturedProbe->statusKey);
        self::assertSame(0, $handler->probeInvocations);
        self::assertSame([], $handler->client->sent);
    }

    /**
     * A non-empty submitted token wins the precedence: the connection the probe seam receives carries the
     * typed token, not any persisted one.
     *
     * @return void
     */
    #[Test]
    public function testActionUsesTheSubmittedTokenWhenPresent(): void
    {
        $this->module->setPreference('finder_token', 'saved');

        $handler = $this->capturingHandler([
            static fn (): ResponseInterface => self::jsonResponse(self::validCapabilitiesBody()),
        ]);

        $handler->handle($this->panelPost([
            'action'    => 'test',
            'transport' => 'rest',
            'base_url'  => 'https://finder.example',
            'token'     => 'typed',
        ]));

        self::assertNotNull($handler->capturedConnection);
        self::assertSame('typed', $handler->capturedConnection->token());
    }

    /**
     * A blank submitted token with the remove flag unset falls back to the persisted token: the probe
     * connection carries the saved token so the admin can re-test without re-entering it.
     *
     * @return void
     */
    #[Test]
    public function testActionFallsBackToThePersistedTokenWhenBlank(): void
    {
        $this->module->setPreference('finder_token', 'saved');

        $handler = $this->capturingHandler([
            static fn (): ResponseInterface => self::jsonResponse(self::validCapabilitiesBody()),
        ]);

        $handler->handle($this->panelPost([
            'action'    => 'test',
            'transport' => 'rest',
            'base_url'  => 'https://finder.example',
            'token'     => '',
        ]));

        self::assertNotNull($handler->capturedConnection);
        self::assertSame('saved', $handler->capturedConnection->token());
    }

    /**
     * The explicit remove-token flag forces an unauthenticated probe even when a token is persisted: the
     * probe connection carries no token.
     *
     * @return void
     */
    #[Test]
    public function testActionWithoutTokenWhenRemoveChecked(): void
    {
        $this->module->setPreference('finder_token', 'saved');

        $handler = $this->capturingHandler([
            static fn (): ResponseInterface => self::jsonResponse(self::validCapabilitiesBody()),
        ]);

        $handler->handle($this->panelPost([
            'action'       => 'test',
            'transport'    => 'rest',
            'base_url'     => 'https://finder.example',
            'token'        => '',
            'remove_token' => '1',
        ]));

        self::assertNotNull($handler->capturedConnection);
        self::assertNull($handler->capturedConnection->token());
    }

    /**
     * An invalid base URL renders an invalid readout WITHOUT probing: the connection is rejected at the
     * single {@see FinderConnection::rest()} source, so the probe seam is never invoked and the client
     * recorded zero sent requests.
     *
     * @return void
     */
    #[Test]
    public function testActionInvalidBaseUrlRendersInvalidWithoutProbing(): void
    {
        $handler = $this->capturingHandler([]);

        $handler->handle($this->panelPost([
            'action'    => 'test',
            'transport' => 'rest',
            'base_url'  => 'nope',
        ]));

        self::assertNotNull($handler->capturedProbe);
        self::assertSame('invalid', $handler->capturedProbe->statusKey);
        self::assertSame(0, $handler->probeInvocations);
        self::assertSame([], $handler->client->sent);
    }

    /**
     * Builds a handler that drives the capabilities probe over a SCRIPTED PSR-18 double rather than a
     * real HTTP client (the probe is final readonly and cannot be stubbed), capturing the
     * {@see FinderConnection} the seam receives (for the token-precedence asserts), counting the probe
     * invocations and capturing the {@see FinderConnectionView} the re-render receives — so the tests
     * assert on the mapped readout without depending on the Task 7 template.
     *
     * @param list<callable(RequestInterface): ResponseInterface> $script The scripted probe responders.
     *
     * @return ObituaryControlPanelHandler&object{capturedProbe: ?ProbeReadoutView, capturedConnection: ?FinderConnection, probeInvocations: int, client: ScriptablePsr18Client} The capturing handler.
     */
    private function capturingHandler(array $script): ObituaryControlPanelHandler
    {
        return new class($this->module, new ScriptablePsr18Client($script)) extends ObituaryControlPanelHandler {
            /**
             * @var ProbeReadoutView|null The readout the re-render received, captured for assertions.
             */
            public ?ProbeReadoutView $capturedProbe = null;

            /**
             * @var FinderConnection|null The connection the probe seam received, captured for assertions.
             */
            public ?FinderConnection $capturedConnection = null;

            /**
             * @var int The number of times the probe seam was invoked.
             */
            public int $probeInvocations = 0;

            /**
             * @param ObituaryMatcherModule $module The module instance.
             * @param ScriptablePsr18Client $client The scripted client the probe sends through.
             */
            public function __construct(
                ObituaryMatcherModule $module,
                public ScriptablePsr18Client $client,
            ) {
                parent::__construct($module);
            }

            protected function capabilitiesProbe(FinderConnection $connection): FinderCapabilitiesProbe
            {
                ++$this->probeInvocations;
                $this->capturedConnection = $connection;

                return new FinderCapabilitiesProbe($this->client, new HttpFactory(), $connection);
            }

            protected function renderPanelWith(FinderConnectionView $finder): ResponseInterface
            {
                $this->capturedProbe = $finder->probe;

                return new Response(StatusCodeInterface::STATUS_OK);
            }
        };
    }

    /**
     * A valid capabilities document the scripted client answers a reachable probe with, built from the
     * #56 contract shape: the required finder id, retention window, schema versions and a single portal.
     *
     * @return array<string, mixed> The valid capabilities body.
     */
    private static function validCapabilitiesBody(): array
    {
        return [
            'finderId'         => 'finder-x',
            'finderVersion'    => '1.2.3',
            'retentionSeconds' => 86_400,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
        ];
    }

    /**
     * Builds a 200 JSON response carrying the given decoded body, for the scripted probe responder.
     *
     * @param array<string, mixed> $data The body to JSON-encode.
     *
     * @return ResponseInterface The JSON response.
     */
    private static function jsonResponse(array $data): ResponseInterface
    {
        return new Response(
            StatusCodeInterface::STATUS_OK,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR),
        );
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
                            new CandidateRepository(),
                            new FeederRequestFactory(new QueryGenerator()),
                            new UrlHostNormalizer(),
                            new TreeService(new GedcomImportService()),
                            new FileJobTransport(
                                new QueueClient($paths),
                                new ResponseReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
                                new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
                                $paths,
                            ),
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
        $data = AtomicFile::readJsonCapped($path, QueueLimits::FEEDER_FILE_MAX_BYTES);

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $data['candidates'];

        return count($candidates);
    }
}
