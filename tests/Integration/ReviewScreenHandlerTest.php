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
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\Middleware\CheckCsrf;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ReviewScreenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

use function str_repeat;
use function view;

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
#[UsesClass(CheckCsrf::class)]
#[UsesClass(MatchSeeder::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(ReviewViewModel::class)]
#[UsesClass(SuggestionViewModel::class)]
#[UsesClass(TreePersonView::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(StoredMatchKey::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(BandKey::class)]
#[UsesClass(ObituaryDateFormatter::class)]
#[UsesClass(SourceLink::class)]
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

        // The fixture individual carries a real birth AND death date so the render test exercises the
        // live Individual::getBirthDate()/getDeathDate()->display() path, which webtrees emits as HTML
        // (a `<span class="date">…</span>`) — the very markup the plain-text TreePersonView DTO must
        // not leak through the escaping template.
        $this->tree = $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n1 BIRT\n2 DATE 4 SEP 1901\n2 PLAC Berlin\n1 DEAT\n2 DATE 25 JAN 1932\n"
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
     * GET renders the review screen for a seeded pending row, including the source host, the seeded
     * score, the band label and the status block (spec §11). The seeder stamps the `strong` band,
     * which maps to score 92, so all three escaped fields must reach the body.
     *
     * @return void
     */
    #[Test]
    public function getRendersSeededRow(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('trauer.example', $body);
        // The seeded strong band maps to score 92; the score value reaches the rendered body.
        self::assertStringContainsString('<span class="om-score-value">92</span>', $body);
        // The band block renders the translated "Strong match" label, not the raw band key.
        self::assertStringContainsString('<span class="om-band">Strong match</span>', $body);
        // The status block renders the translated pending label for a freshly seeded row.
        self::assertStringContainsString('<span class="om-status">Pending</span>', $body);
        // The "Confirm as source" affordance renders but stays disabled in this slice — write-back is
        // 2d-3 (spec §2/§12 + smoke step 5). This re-homes the disabled-button render assertion that
        // was lost when the standalone TabViewTest was removed.
        self::assertStringContainsString('<button type="button" disabled>Confirm as source</button>', $body);
        // The tree-person birth and death dates come from the live Individual::getBirthDate()/
        // getDeathDate()->display(), which webtrees emits as a `<span class="date">…</span>`. The
        // TreePersonView DTO is plain text that the template e()-escapes, so the date text must reach
        // the body as plain "1901"/"1932" — and the escaped `&lt;span` markup must NOT leak (the live
        // smoke bug: the manager saw the literal `<span class="date">…</span>` instead of the date).
        self::assertStringContainsString('1901', $body);
        self::assertStringContainsString('1932', $body);
        self::assertStringNotContainsString('&lt;span', $body);
    }

    /**
     * GET renders the full runner-up summary (spec §6): a seeded pending row carrying a runner-up must
     * surface its name, birth year, birth place and the translated classification band label — not just
     * the name and score. The runner-up is stamped `probable`, which maps to the translated "Probable
     * match" label, so the raw band key must NOT leak into the rendered body.
     *
     * @return void
     */
    #[Test]
    public function getRendersFullRunnerUpSummary(): void
    {
        $key     = $this->seedPendingMatchWithRunnerUp('I1');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The runner-up section now renders the full spec §6 field set, not just name + score. The
        // birth year and place are anchored to the rendered "Born:" line so a bare-substring match
        // can't pass on an unrelated occurrence.
        self::assertStringContainsString('Karl Vorbild', $body);
        self::assertStringContainsString('Born: 1940 · Beispieldorf', $body);
        // The classification renders through the band-label map: the translated "Probable match" label
        // reaches the body inside the runner-up band span, proving the lookup ran rather than emitting
        // the raw `probable` key.
        self::assertStringContainsString('<span class="om-band">Probable match</span>', $body);
    }

    /**
     * The tab link's row key resolves the same row via findOne (VM ↔ store normalisation parity).
     * The row is seeded with a raw, un-normalised URL (mixed case + tracking query) so the assertion
     * only holds when the tab's {@see SuggestionViewModel::$rowKey} and the store apply the exact same
     * identity normalisation.
     *
     * @return void
     */
    #[Test]
    public function tabRowKeyResolvesViaFindOne(): void
    {
        $url = 'https://Trauer.Example/a?utm_source=x';
        $this->seedPendingMatchRaw('I1', $url);

        $vm = SuggestionViewModel::fromStoredMatch($this->store()->findByPerson('I1')[0]);

        // The raw mixed-case + tracking-param URL collapses to the same canonical key as the plain
        // host/path — pinning the literal anchors the normalisation so a regression that stopped
        // lowercasing the host or stripping `utm_source` flips this and fails.
        self::assertSame('89b60f2d1bdf98d97c9b78ab815b88247d26166e08271c838dcc270f90007d29', $vm->rowKey);
        // Cross-boundary: the key the tab href carries resolves the seeded row through the store.
        self::assertInstanceOf(StoredMatch::class, $this->store()->findOne('I1', $vm->rowKey));
    }

    /**
     * The rendered tab links the "Review" affordance to the booted review route, carrying the same
     * row key the store resolves: rendering needs the registered route, so this lives in the booted
     * integration case rather than a routeless unit render.
     *
     * @return void
     */
    #[Test]
    public function tabRendersReviewLinkToBootedRoute(): void
    {
        $html = $this->renderTabFor('https://Trauer.Example/a?utm_source=x');

        self::assertStringContainsString('class="om-review-link"', $html);
        self::assertStringContainsString('obituary-review', $html);
        self::assertStringContainsString($this->rowKeyFor('https://Trauer.Example/a?utm_source=x'), $html);
        // The HTTP source notice is still linked out, and the count line reflects the single row.
        self::assertStringContainsString('href="https://Trauer.Example/a?utm_source=x"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('1 open suggestion', $html);
    }

    /**
     * A non-HTTP source URL is refused as a link: the rendered tab carries the review affordance but
     * no outbound source anchor (`target="_blank"`) and — re-homing the old `nullSourceRowHasNoHref`
     * intent — no active anchor for the refused scheme at all (`href="javascript:…"`), proving the
     * {@see SuggestionViewModel} HTTP-only guard reaches the template rather than leaking the raw
     * `javascript:` URL into an href.
     *
     * @return void
     */
    #[Test]
    public function tabRefusesNonHttpSourceLink(): void
    {
        $html = $this->renderTabFor('javascript:alert(1)');

        self::assertStringContainsString('class="om-review-link"', $html);
        self::assertStringNotContainsString('target="_blank"', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
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
     * A malformed (non-hex) key 404s at the regex pre-filter, before any row lookup. This
     * distinguishes the `^[a-f0-9]{64}$` guard branch from the well-formed-but-absent row path that
     * {@see getUnknownKey404s()} exercises.
     *
     * @return void
     */
    #[Test]
    public function getMalformedKey404s(): void
    {
        $request = $this->managerGetRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'I1', 'key' => 'not-a-hash']
        );

        $this->expectException(HttpNotFoundException::class);

        $this->handler()->handle($request);
    }

    /**
     * A non-manager (here a guest) is denied even with a seeded pending row, exercising the real
     * {@see Auth::isManager()} gate rather than the admin happy path.
     *
     * @return void
     */
    #[Test]
    public function getNonManagerIsDenied(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->nonManagerGetRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'I1', 'key' => $key]
        );

        $this->expectException(HttpAccessDeniedException::class);

        $this->handler()->handle($request);
    }

    /**
     * A well-formed-but-nonexistent XREF makes {@see Registry::individualFactory()} return null, so
     * the {@see Auth::checkIndividualAccess()} gate throws {@see HttpNotFoundException}. This exercises
     * the individual-access gate as a manager without needing a non-admin/private-record combination.
     *
     * @return void
     */
    #[Test]
    public function getAbsentIndividualIsNotFound(): void
    {
        $key     = $this->seedPendingMatch('X9999');
        $request = $this->managerGetRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'X9999', 'key' => $key]
        );

        $this->expectException(HttpNotFoundException::class);

        $this->handler()->handle($request);
    }

    /**
     * POST reject moves the row to rejected and redirects to the individual page.
     *
     * @return void
     */
    #[Test]
    public function postRejectFinalisesAndRedirects(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'reject']
        );

        $response = $this->handler()->handle($request);

        self::assertSame(302, $response->getStatusCode());
        // Reject is terminal, so the reviewer lands back on the individual page, not the review screen.
        self::assertStringContainsString('individual', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('obituary-review', $response->getHeaderLine('Location'));
        self::assertSame([], $this->store()->allPending());
    }

    /**
     * A POST carrying a valid CSRF token passes webtrees' router-injected {@see CheckCsrf} middleware
     * and reaches the handler, which finalises the row and redirects (302). The middleware is the real
     * contract that guards every matched POST route; a direct {@see RequestHandlerInterface::handle()}
     * call bypasses it (middleware runs in the router stack, not on the handler), so this test
     * dispatches the POST THROUGH the middleware with the session token mirrored into the body — exactly
     * how webtrees' kernel feeds it — proving the valid-token path succeeds (spec §11).
     *
     * @return void
     */
    #[Test]
    public function postWithValidCsrfTokenPassesMiddlewareAndSucceeds(): void
    {
        $key   = $this->seedPendingMatch('I1');
        $token = 'a-valid-csrf-token';
        Session::put('CSRF_TOKEN', $token);

        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'reject', '_csrf' => $token]
        );

        $response = (new CheckCsrf())->process($request, $this->handler());

        self::assertSame(302, $response->getStatusCode());
        // A successful pass-through redirects to the individual page (reject is terminal); the row is
        // finalised, which a CSRF rejection (a redirect back to the same URI, no mutation) would not do.
        self::assertStringContainsString('individual', $response->getHeaderLine('Location'));
        self::assertSame([], $this->store()->allPending());
    }

    /**
     * A POST WITHOUT a valid CSRF token is rejected by the {@see CheckCsrf} middleware before the
     * handler runs: it redirects back to the same URI with a flash and never finalises the row (spec
     * §11). The absent token mismatches the session token, so the middleware short-circuits.
     *
     * @return void
     */
    #[Test]
    public function postWithoutValidCsrfTokenIsRejectedByMiddleware(): void
    {
        $key = $this->seedPendingMatch('I1');
        Session::put('CSRF_TOKEN', 'the-session-token');

        // No `_csrf` field in the body: the empty client token mismatches the session token.
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'reject']
        );

        $response = (new CheckCsrf())->process($request, $this->handler());

        self::assertSame(302, $response->getStatusCode());
        // The middleware redirects back to the same request URI (not the handler's individual-page
        // target) and the row is untouched — proving the handler never ran.
        self::assertStringContainsString('index.php', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('individual', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->store()->allPending());
    }

    /**
     * POST uncertain moves the row to uncertain (still non-terminal) and redirects back to review.
     *
     * @return void
     */
    #[Test]
    public function postUncertainKeepsRowAndRedirectsToReview(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'uncertain']
        );

        $response = $this->handler()->handle($request);

        self::assertSame(302, $response->getStatusCode());
        // Uncertain is non-terminal, so it loops back to the review screen (not the individual page).
        self::assertStringContainsString('obituary-review', $response->getHeaderLine('Location'));
        self::assertSame(MatchStatus::Uncertain, $this->store()->findOne('I1', $key)?->status);

        // The redirect loops the manager back to the review route; re-issuing that GET must render the
        // new uncertain status block — proving the action took visible effect, not just a store mutation
        // (spec §11 + smoke step 3). A fresh handler over the same temp store reads the mutated row.
        $reload = $this->handler()->handle(
            $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key])
        );

        self::assertSame(200, $reload->getStatusCode());
        self::assertStringContainsString('<span class="om-status">Uncertain</span>', (string) $reload->getBody());
    }

    /**
     * A POST carrying an unknown decision action is a bad request: the row resolves (a real pending
     * row is seeded so resolveRow passes), then applyDecision's default arm throws
     * HttpBadRequestException rather than silently doing nothing.
     *
     * @return void
     */
    #[Test]
    public function postWithUnknownActionIsBadRequest(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'bogus']
        );

        $this->expectException(HttpBadRequestException::class);

        $this->handler()->handle($request);
    }

    /**
     * A row already terminal BEFORE the POST is a clean 404 via resolveRow — not reviewable.
     *
     * @return void
     */
    #[Test]
    public function postOnPreResolveTerminalRow404s(): void
    {
        $key = $this->seedPendingMatch('I1');
        $this->store()->markRejected('I1', 'https://trauer.example/I1', null);

        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'uncertain']
        );

        $this->expectException(HttpNotFoundException::class);

        $this->handler()->handle($request);
    }

    /**
     * The real TOCTOU: resolveRow sees a pending row, but the store mutation throws because another
     * manager finalised it in between. The handler catches the throw and redirects (302) with a
     * warning flash — it does NOT 500. The store seam injects the race.
     *
     * @return void
     */
    #[Test]
    public function postOnMidActionRaceRedirectsNot500(): void
    {
        $racingStore = new class implements MatchStore {
            /**
             * Returns a pending row so resolveRow passes, then markUncertain throws below.
             *
             * @param string $personId The candidate identifier.
             * @param string $rowKey   The canonical row key.
             *
             * @return StoredMatch The pending row (covariantly narrowed: this fake never returns null).
             */
            public function findOne(string $personId, string $rowKey): StoredMatch
            {
                return new StoredMatch(
                    $personId,
                    'https://trauer.example/I1',
                    MatchStatus::Pending,
                    ClassifiedMatch::emptyArray($personId, 'https://trauer.example/I1'),
                );
            }

            /**
             * Throws to simulate the row turning terminal between resolve and mutate.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source URL.
             * @param string|null $reason      The reviewer note.
             *
             * @return void
             */
            public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
            {
                throw new TerminalMatchTransitionException('raced');
            }

            /**
             * Throws to simulate the row turning terminal between resolve and mutate.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source URL.
             * @param string|null $reason      The rejection reason.
             *
             * @return void
             */
            public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
            {
                throw new TerminalMatchTransitionException('raced');
            }

            /**
             * Unused by the race scenario; accepts the write and reports success.
             *
             * @param StoredMatch $match The suggestion to store.
             *
             * @return bool Always true.
             */
            public function upsertPending(StoredMatch $match): bool
            {
                return true;
            }

            /**
             * Unused by the race scenario; returns no rows.
             *
             * @param string $personId The candidate identifier.
             *
             * @return list<StoredMatch> The empty result.
             */
            public function findByPerson(string $personId): array
            {
                return [];
            }

            /**
             * Unused by the race scenario; returns no pending rows.
             *
             * @return list<StoredMatch> The empty result.
             */
            public function allPending(): array
            {
                return [];
            }
        };

        // A handler subclass that injects the racing store via the storeForTree seam.
        $handler = new class(self::MODULE_NAMESPACE, $racingStore) extends ReviewScreenHandler {
            /**
             * Wraps the handler so the mid-action terminal race fires through the injected store.
             *
             * @param string     $namespace   The view namespace the handler renders under.
             * @param MatchStore $racingStore The store whose mutation throws to simulate the race.
             */
            public function __construct(string $namespace, private readonly MatchStore $racingStore)
            {
                parent::__construct($namespace);
            }

            /**
             * Returns the racing store so the mid-action terminal race fires on mutation.
             *
             * @param Tree $tree The tree whose store is requested.
             *
             * @return MatchStore The racing store.
             */
            protected function storeForTree(Tree $tree): MatchStore
            {
                return $this->racingStore;
            }
        };

        $key     = str_repeat('a', 64);
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I1', 'key' => $key],
            ['action' => 'uncertain']
        );

        $response = $handler->handle($request);

        self::assertSame(302, $response->getStatusCode());
        // The catch redirects to the individual page; a swallowed exception falling through to the
        // uncertain success path would instead loop back to the review screen — so the absence of
        // `obituary-review` (plus the warning flash below) is what proves the catch branch fired.
        self::assertStringContainsString('individual', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('obituary-review', $response->getHeaderLine('Location'));

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('warning', $messages[0]->status);
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
     * Upserts a pending row for the given candidate carrying a raw, un-normalised source URL. Unlike
     * {@see seedPendingMatch()}, which fabricates the URL from the XREF, this lets a test pin an
     * arbitrary URL so the VM-key ↔ store-key normalisation parity can be exercised end-to-end.
     *
     * @param string $xref The candidate identifier.
     * @param string $url  The raw, pre-normalisation source notice URL.
     *
     * @return void
     */
    private function seedPendingMatchRaw(string $xref, string $url): void
    {
        $this->store()->upsertPending(
            new StoredMatch(
                $xref,
                $url,
                MatchStatus::Pending,
                ClassifiedMatch::emptyArray($xref, $url),
            )
        );
    }

    /**
     * Upserts a pending row for the given candidate carrying a fully-populated runner-up summary so the
     * review screen's runner-up block can be exercised end-to-end. The {@see MatchSeeder} fabricates no
     * runner-up, so the payload is built from the canonical zero-value shape with only the runner-up
     * overridden — mirroring how {@see seedPendingMatchRaw()} writes a tailored row.
     *
     * @param string $xref The candidate identifier.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedPendingMatchWithRunnerUp(string $xref): string
    {
        $obituaryUrl         = 'https://trauer.example/' . $xref;
        $payload             = ClassifiedMatch::emptyArray($xref, $obituaryUrl);
        $payload['runnerUp'] = [
            'personId'       => 'I2',
            'score'          => 74,
            'classification' => 'probable',
            'name'           => 'Karl Vorbild',
            'birthYear'      => 1940,
            'birthPlace'     => 'Beispieldorf',
        ];

        $this->store()->upsertPending(new StoredMatch($xref, $obituaryUrl, MatchStatus::Pending, $payload));

        return StoredMatchKey::fromUrl($obituaryUrl);
    }

    /**
     * Seeds I1 with the given raw source URL, binds the base request the `route()` helper needs and
     * renders the individual tab over the booted route, returning the produced HTML.
     *
     * @param string $url The raw, pre-normalisation source notice URL the seeded row carries.
     *
     * @return string The rendered tab HTML.
     */
    private function renderTabFor(string $url): string
    {
        $this->seedPendingMatchRaw('I1', $url);
        $this->bindBaseRequest();

        $individual = $this->individual('I1', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);

        return view(self::MODULE_NAMESPACE . '::tab', [
            'individual'  => $individual,
            'suggestions' => [SuggestionViewModel::fromStoredMatch($this->store()->findByPerson('I1')[0])],
        ]);
    }

    /**
     * Returns the canonical row key for the given source URL, as the tab link and the store both
     * derive it.
     *
     * @param string $url The raw, pre-normalisation source notice URL.
     *
     * @return string The canonical row key.
     */
    private function rowKeyFor(string $url): string
    {
        return StoredMatchKey::fromUrl($url);
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
        return $this->getRequestAs(Auth::user(), $routeName, $attributes);
    }

    /**
     * Builds a manager-authenticated POST request carrying the tree, the route attributes and the
     * given parsed body. The logged-in administrator is a manager of every tree.
     *
     * @param string                $routeName  The route name carried on the route attribute.
     * @param array<string, string> $attributes The route attributes (xref, key).
     * @param array<string, string> $body       The parsed request body (action).
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function managerPostRequest(string $routeName, array $attributes, array $body): ServerRequestInterface
    {
        return $this->getRequestAs(Auth::user(), $routeName, $attributes, RequestMethodInterface::METHOD_POST, $body);
    }

    /**
     * Builds a GET request authenticated as a non-manager (a guest, who is neither an administrator
     * nor holds the tree's manager role) so the real {@see Auth::isManager()} deny-branch fires. The
     * guest is attached as the request's `user` attribute exactly as webtrees' middleware would.
     *
     * @param string                $routeName  The route name carried on the route attribute.
     * @param array<string, string> $attributes The route attributes (xref, key).
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function nonManagerGetRequest(string $routeName, array $attributes): ServerRequestInterface
    {
        return $this->getRequestAs(new GuestUser(), $routeName, $attributes);
    }

    /**
     * Binds a minimal manager request into the container so the `route()` helper the tab template
     * calls can resolve the base URL when rendering outside the handler. Mirrors what webtrees'
     * routing middleware sets up for an ordinary request.
     *
     * @return void
     */
    private function bindBaseRequest(): void
    {
        $this->getRequestAs(Auth::user(), ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1']);
    }

    /**
     * Builds a request carrying the tree, the route, the given user as request attributes and an
     * optional parsed body. Defaults to GET with an empty body for the read-only render tests.
     *
     * @param UserInterface         $user       The user attached as the request's `user` attribute.
     * @param string                $routeName  The route name carried on the route attribute.
     * @param array<string, string> $attributes The route attributes (xref, key).
     * @param string                $method     The HTTP method.
     * @param array<string, string> $body       The parsed request body.
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function getRequestAs(
        UserInterface $user,
        string $routeName,
        array $attributes,
        string $method = RequestMethodInterface::METHOD_GET,
        array $body = [],
    ): ServerRequestInterface {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name($routeName);

        $request = $factory
            ->createServerRequest($method, 'https://webtrees.test/index.php')
            ->withParsedBody($body)
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $this->tree)
            ->withAttribute('user', $user);

        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        Registry::container()->set(Tree::class, $this->tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }
}
