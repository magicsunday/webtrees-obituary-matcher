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
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Support\ConfirmDecision;
use MagicSunday\ObituaryMatcher\Support\ConfirmGate;
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\PortalSourceRepository;
use MagicSunday\ObituaryMatcher\Webtrees\ReviewScreenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

use function iterator_to_array;
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
#[UsesClass(ConfirmGate::class)]
#[UsesClass(ConfirmDecision::class)]
#[UsesClass(GedcomDateConverter::class)]
#[UsesClass(ObituaryWriteBack::class)]
#[UsesClass(PortalSourceRepository::class)]
#[UsesClass(WriteBack::class)]
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
        // not leak through the escaping template. A second individual @I2@ carries a birth PLACE but NO
        // birth date, so the render test can exercise the place-only branch (a date-less birth event)
        // through the real handler→template path.
        $this->tree = $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n1 BIRT\n2 DATE 4 SEP 1901\n2 PLAC Berlin\n1 DEAT\n2 DATE 25 JAN 1932\n"
            . "0 @I2@ INDI\n1 NAME Emma /Ortlos/\n1 SEX F\n1 BIRT\n2 PLAC Hamburg\n"
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
        // The seeded I1 already carries a death date, so the confirm gate denies with the
        // `tree_already_has_death_date` reason: the Confirm button renders DISABLED (carrying the
        // reason as its title) and the human-readable reason is surfaced alongside it — never as an
        // enabled confirm form. This re-homes the disabled-button render assertion that was lost when
        // the standalone TabViewTest was removed.
        self::assertStringContainsString('disabled title="This individual already has a death date.">Confirm as source</button>', $body);
        self::assertStringContainsString('<span class="om-confirm-reason">This individual already has a death date.</span>', $body);
        // The disabled-gate branch must not emit an enabled confirm form (no confirm POST affordance).
        self::assertStringNotContainsString('value="confirm"', $body);
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
     * GET renders the tree-person birth PLACE even when the birth DATE is missing (Gemini thread 1):
     * individual I2 carries a `1 BIRT` with only a `2 PLAC` (no `2 DATE`). The place must reach the
     * body behind the translated "Born" label — proving the place-only branch renders, not date+place.
     *
     * @return void
     */
    #[Test]
    public function getRendersTreePersonBirthPlaceWithoutDate(): void
    {
        $key     = $this->seedPendingMatch('I2');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I2', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The date-less birth event still surfaces the place behind the translated "Born" label.
        self::assertStringContainsString('Born: Hamburg', $body);
    }

    /**
     * GET renders the runner-up birth PLACE behind the translated "Born" label even when the birth
     * YEAR is missing (Gemini thread 3): the place-only branch must read "Born: place", consistent
     * with the tree-person place-only branch — not a bare, unlabelled place.
     *
     * @return void
     */
    #[Test]
    public function getRendersRunnerUpBirthPlaceWithoutYearWithLabel(): void
    {
        $key     = $this->seedPendingMatchWithRunnerUp('I1', null);
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The year-less runner-up surfaces its place behind the same translated "Born" label.
        self::assertStringContainsString('Born: Beispieldorf', $body);
    }

    /**
     * GET renders the obituary extracted-fact keys behind translated labels (Gemini thread 2): a
     * seeded `place` fact must surface as the translated "Place" label, not the raw producer key.
     *
     * @return void
     */
    #[Test]
    public function getRendersExtractedFactKeysWithTranslatedLabel(): void
    {
        $key     = $this->seedPendingMatchWithExtractedFact('I1', 'place', 'Berlin');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The known `place` fact key renders through the label map as the translated "Place" label …
        self::assertStringContainsString('Place: Berlin', $body);
        // … so the raw, untranslated `place:` key must NOT leak into the obituary fact line.
        self::assertStringNotContainsString('place: Berlin', $body);
    }

    /**
     * GET renders the conflict field name behind a translated label (Gemini fix-wave R2): a seeded
     * `death date` conflict — one of the three field names {@see ConflictDetector} emits — must surface
     * as the translated "Death date" label in the conflicts block, not the raw producer field string.
     *
     * @return void
     */
    #[Test]
    public function getRendersConflictFieldWithTranslatedLabel(): void
    {
        $key     = $this->seedPendingMatchWithConflict('I1', 'death date', 'hard');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I1', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The known `death date` conflict field renders through the label map as the translated
        // "Death date" label inside the conflict line …
        self::assertStringContainsString('Death date: 1901 ↔ 1950', $body);
        // … so the raw, untranslated `death date:` field string must NOT leak into the conflict line.
        self::assertStringNotContainsString('death date: 1901', $body);
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
     * A POST carrying NO `action` field is a bad request, exactly like an unknown action (Gemini
     * thread 4): a real pending row is seeded so resolveRow passes, then the defaulted empty action
     * flows to applyDecision's switch default arm, which throws HttpBadRequestException — so the
     * handler owns the bad-request semantics uniformly for both the missing and the unknown case.
     *
     * @return void
     */
    #[Test]
    public function postWithMissingActionIsBadRequest(): void
    {
        $key     = $this->seedPendingMatch('I1');
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'I1', 'key' => $key],
            []
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
             * @param string    $personId    The candidate identifier.
             * @param string    $obituaryUrl The source URL.
             * @param WriteBack $writeBack   The write-back IDs.
             *
             * @return bool Never returns; always throws in this race fake.
             */
            public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
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
     * GET renders the ENABLED confirm form when the gate passes: I2 has no death date and the seeded
     * row carries an exact ISO date, so the review screen emits a real confirm POST form (a submit
     * button + the hidden `confirm` action), not the disabled placeholder.
     *
     * @return void
     */
    #[Test]
    public function getRendersEnabledConfirmFormWhenGatePasses(): void
    {
        $key     = $this->seedConfirmableMatch('I2', '2023-09-04');
        $request = $this->managerGetRequest(ReviewScreenHandler::ROUTE_NAME, ['xref' => 'I2', 'key' => $key]);

        $response = $this->handler()->handle($request);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // The confirmable row renders a real confirm POST form, not the disabled placeholder.
        self::assertStringContainsString('<input type="hidden" name="action" value="confirm">', $body);
        self::assertStringContainsString('<button type="submit">Confirm as source</button>', $body);
        self::assertStringNotContainsString('disabled title=', $body);
    }

    /**
     * POST confirm writes the sourced DEAT, marks the store confirmed and redirects to the individual
     * with a success flash. I2 carries no death date and the seeded row carries an exact ISO date, so
     * the gate passes; auto-accept is ON so the written DEAT commits and the re-fetched individual and
     * the persisted store row both reflect the confirm (spec §2/§9).
     *
     * @return void
     */
    #[Test]
    public function postConfirmWritesMarksAndRedirectsToIndividual(): void
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        $key = $this->seedConfirmableMatch('I2', '2023-09-04');

        $this->postConfirmAndAssertSuccessRedirect('I2', $key);

        // The store row transitioned to Confirmed and persisted the WriteBack from the write. A
        // re-read row reconstructs the write-back as its serialised array shape (StoredMatch::fromArray),
        // so the deatFactId from the write round-tripped through the store rather than being dropped.
        $row = $this->store()->findOne('I2', $key);
        self::assertInstanceOf(StoredMatch::class, $row);
        self::assertSame(MatchStatus::Confirmed, $row->status);
        self::assertIsArray($row->writeBack);
        self::assertArrayHasKey('deatFactId', $row->writeBack);
        self::assertNotSame('', $row->writeBack['deatFactId']);

        // The live individual now carries exactly one dated DEAT fact (the write reached the tree).
        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);

        $facts = iterator_to_array($individual->facts(['DEAT'], false, null, true));
        self::assertCount(1, $facts);
        self::assertStringContainsString('2 DATE 4 SEP 2023', $facts[0]->gedcom());
    }

    /**
     * POST confirm on a row the gate refuses (the tree person already has a death date) writes nothing
     * and does not transition: the server-side ConfirmGate re-check fails (I1 carries a DEAT), so the
     * handler flashes a warning, redirects back to review and leaves the row pending. The disabled
     * Confirm button is NOT the authorization control — a hand-crafted POST must still be refused.
     *
     * @return void
     */
    #[Test]
    public function postConfirmRefusedByGateWhenTreeHasDeathDate(): void
    {
        // I1 carries a DEAT in the fixture, so the gate's treeHasDeathDate conjunct fails.
        $key = $this->seedConfirmableMatch('I1', '2023-09-04');

        $this->assertConfirmRefusedNoTransition('I1', $key);
    }

    /**
     * POST confirm whose writeDeath throws a precondition failure (a stored non-http URL) flashes a
     * warning and does NOT transition: the write aborts cleanly, no GEDCOM is written and the store
     * row stays pending (Block A of the separate try/catch, spec §9).
     *
     * @return void
     */
    #[Test]
    public function postConfirmWriteBackPreconditionFailDoesNotTransition(): void
    {
        // A confirmable gate state (I2 has no death date, exact ISO date) but a non-http stored URL:
        // the gate passes, then writeDeath throws WriteBackPreconditionException on the bad URL.
        $key = $this->seedConfirmableMatchRaw('I2', 'ftp://trauer.example/I2', '2023-09-04');

        $this->assertConfirmRefusedNoTransition('I2', $key);

        // The write aborted before any GEDCOM was written, so no DEAT reached the tree.
        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(0, iterator_to_array($individual->facts(['DEAT'], false, null, true)));
    }

    /**
     * POST confirm where the write succeeds but markConfirmed returns false (the row was already
     * finalised by someone else, or vanished) flashes an "already finalised" warning, NOT the
     * confirmed-success message: a false return is not success (spec §9). The writer seam writes
     * fine and the store seam returns false.
     *
     * @return void
     */
    #[Test]
    public function postConfirmMarkConfirmedFalseFlashesAlreadyFinalised(): void
    {
        // The write succeeds (auto-accept on, gate passes) but markConfirmed returns false: a false
        // return is not success, so the handler flashes a warning rather than the confirmed message.
        $status = $this->runConfirmThroughStore($this->storeMarkingConfirmed(false));

        self::assertSame('warning', $status);
    }

    /**
     * POST confirm where the write succeeds but markConfirmed throws a non-terminal Throwable (Block B,
     * the orphan-risk path: the DEAT was written but the store could not record it) flashes a danger
     * error, NOT a success message (spec §9). The writer seam writes fine; the store seam throws.
     *
     * @return void
     */
    #[Test]
    public function postConfirmStoreThrowsAfterWriteFlashesOrphanError(): void
    {
        // The write succeeds but markConfirmed throws: the DEAT is orphaned, so the handler surfaces a
        // danger error (logged for reconciliation) rather than reporting success.
        $status = $this->runConfirmThroughStore($this->storeThrowingOnConfirmed(new RuntimeException('store down')));

        self::assertSame('danger', $status);
    }

    /**
     * A POST confirm WITHOUT a valid CSRF token is rejected by the {@see CheckCsrf} middleware before
     * the handler runs: it redirects back with no write and no transition. Mirrors the reject/uncertain
     * CSRF coverage for the confirm action (spec §11).
     *
     * @return void
     */
    #[Test]
    public function postConfirmWithoutValidCsrfTokenIsRejectedByMiddleware(): void
    {
        $key = $this->seedConfirmableMatch('I2', '2023-09-04');
        Session::put('CSRF_TOKEN', 'the-session-token');

        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I2', 'key' => $key],
            ['action' => 'confirm']
        );

        $response = (new CheckCsrf())->process($request, $this->handler());

        self::assertSame(302, $response->getStatusCode());
        // The middleware redirects back to the same request URI; the row is untouched (still pending).
        self::assertStringContainsString('index.php', $response->getHeaderLine('Location'));
        self::assertSame(MatchStatus::Pending, $this->store()->findOne('I2', $key)?->status);
    }

    /**
     * A non-manager confirm POST is denied by the manager gate (the same gate the GET path enforces),
     * before any write or transition.
     *
     * @return void
     */
    #[Test]
    public function postConfirmNonManagerIsDenied(): void
    {
        $key     = $this->seedConfirmableMatch('I2', '2023-09-04');
        $request = $this->getRequestAs(
            new GuestUser(),
            ReviewScreenHandler::ROUTE_NAME,
            ['xref' => 'I2', 'key' => $key],
            RequestMethodInterface::METHOD_POST,
            ['action' => 'confirm']
        );

        $this->expectException(HttpAccessDeniedException::class);

        $this->handler()->handle($request);
    }

    /**
     * Posts a confirm for the given already-seeded row through the real handler and asserts the success
     * redirect: a 302 to the individual page (not back to review) with exactly one success flash. Shared
     * by the happy-path confirm test and the malformed-but-array missing-hardConflict test, which each
     * add their own distinguishing post-success assertions (write-back round-trip / DEAT count).
     *
     * @param string $xref The candidate identifier whose row was seeded.
     * @param string $key  The canonical row key of the seeded row.
     *
     * @return void
     */
    private function postConfirmAndAssertSuccessRedirect(string $xref, string $key): void
    {
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => $xref, 'key' => $key],
            ['action' => 'confirm']
        );

        $response = $this->handler()->handle($request);

        self::assertSame(302, $response->getStatusCode());
        // Confirm is terminal, so the reviewer lands on the individual page, not the review screen.
        self::assertStringContainsString('individual', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('obituary-review', $response->getHeaderLine('Location'));

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('success', $messages[0]->status);
    }

    /**
     * Posts a confirm for the given already-seeded row through the real handler and asserts it was
     * refused without a write: a 302 back to the review screen, exactly one warning flash and the row
     * left pending. Shared by the gate-refusal and the writeDeath-precondition cases — both abort
     * before any store transition (spec §9), differing only in the abort cause and the no-DEAT check.
     *
     * @param string $xref The candidate identifier whose row was seeded.
     * @param string $key  The canonical row key of the seeded row.
     *
     * @return void
     */
    private function assertConfirmRefusedNoTransition(string $xref, string $key): void
    {
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => $xref, 'key' => $key],
            ['action' => 'confirm']
        );

        $response = $this->handler()->handle($request);

        self::assertSame(302, $response->getStatusCode());
        // Refused before any write — the manager loops back to the review screen, not the individual.
        self::assertStringContainsString('obituary-review', $response->getHeaderLine('Location'));

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('warning', $messages[0]->status);

        // The row never transitioned; it is still pending.
        self::assertSame(MatchStatus::Pending, $this->store()->findOne($xref, $key)?->status);
    }

    /**
     * Drives a confirm POST for a confirmable I2 row through a handler whose store seam is the given
     * double, while the writer writes against the real tree (auto-accept on, so the write succeeds and
     * Block B is reached). Asserts the 302 + exactly one flash and returns that flash's status, so the
     * Block-B return-false and throw cases assert only their distinguishing outcome.
     *
     * @param MatchStore $store The store double driving markConfirmed's outcome.
     *
     * @return string The single flash message's status.
     */
    private function runConfirmThroughStore(MatchStore $store): string
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        $key     = $this->seedConfirmableMatch('I2', '2023-09-04');
        $handler = $this->handlerWith($store);
        $request = $this->managerPostRequest(
            ReviewScreenHandler::ROUTE_NAME,
            ['xref'   => 'I2', 'key' => $key],
            ['action' => 'confirm']
        );

        $response = $handler->handle($request);

        self::assertSame(302, $response->getStatusCode());

        // Block B is only reachable AFTER a successful write: anchor that the DEAT actually reached the
        // tree, so a 'warning' here proves the post-write markConfirmed-false path (not a gate or
        // precondition short-circuit, which would warn without ever writing).
        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(1, iterator_to_array($individual->facts(['DEAT'], false, null, true)));

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);

        return $messages[0]->status;
    }

    /**
     * Builds a confirm handler whose store seam is injected for the Block-B tests. The writer is left
     * as the production {@see ObituaryWriteBack}, so a confirmable row's write reaches the real tree
     * (auto-accept on) and Block B runs against the injected store's markConfirmed outcome.
     *
     * @param MatchStore $store The store the handler resolves and transitions through.
     *
     * @return ReviewScreenHandler The handler with the store seam overridden.
     */
    private function handlerWith(MatchStore $store): ReviewScreenHandler
    {
        return new class(self::MODULE_NAMESPACE, $store) extends ReviewScreenHandler {
            /**
             * @param string     $viewNamespace The view namespace the handler renders under.
             * @param MatchStore $store         The injected store the handler resolves/transitions through.
             */
            public function __construct(string $viewNamespace, private readonly MatchStore $store)
            {
                parent::__construct($viewNamespace);
            }

            /**
             * Returns the injected store so the confirm transition runs against the configured double.
             *
             * @param Tree $tree The tree whose store is requested.
             *
             * @return MatchStore The injected store.
             */
            protected function storeForTree(Tree $tree): MatchStore
            {
                return $this->store;
            }
        };
    }

    /**
     * Wraps this test's temp-directory store in the configurable double so markConfirmed returns the
     * given boolean while findOne still reads the real seeded row.
     *
     * @param bool $result The boolean markConfirmed returns.
     *
     * @return MatchStore The configured store double.
     */
    private function storeMarkingConfirmed(bool $result): MatchStore
    {
        return new ConfigurableConfirmStore($this->store(), $result);
    }

    /**
     * Wraps this test's temp-directory store in the configurable double so markConfirmed throws the
     * given exception (the Block-B orphan path) while findOne still reads the real seeded row.
     *
     * @param Throwable $failure The exception markConfirmed throws.
     *
     * @return MatchStore The configured store double.
     */
    private function storeThrowingOnConfirmed(Throwable $failure): MatchStore
    {
        return new ConfigurableConfirmStore($this->store(), false, $failure);
    }

    /**
     * Seeds a pending row whose payload carries an exact ISO death date and no hard conflict, so the
     * confirm gate passes when the tree person has no death date. The source URL is fabricated from the
     * XREF exactly like {@see seedPendingMatch()}.
     *
     * @param string $xref      The candidate identifier.
     * @param string $deathDate The exact ISO (`YYYY-MM-DD`) death date the obituary carries.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedConfirmableMatch(string $xref, string $deathDate): string
    {
        $match = MatchSeeder::seed($this->store(), $xref, MatchStatus::Pending, 'strong', $deathDate);

        return StoredMatchKey::fromUrl($match->obituaryUrl);
    }

    /**
     * Seeds a confirmable pending row carrying an arbitrary (here non-http) source URL so the confirm
     * write's URL precondition can be exercised end-to-end: the gate still passes (it reads the death
     * date and the tree state, not the URL), then writeDeath rejects the URL.
     *
     * @param string $xref      The candidate identifier.
     * @param string $url       The raw source notice URL (e.g. a non-http scheme).
     * @param string $deathDate The exact ISO (`YYYY-MM-DD`) death date the obituary carries.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedConfirmableMatchRaw(string $xref, string $url, string $deathDate): string
    {
        $payload                   = ClassifiedMatch::emptyArray($xref, $url);
        $payload['extractedFacts'] = ['deathDate' => $deathDate];

        $this->store()->upsertPending(new StoredMatch($xref, $url, MatchStatus::Pending, $payload));

        return StoredMatchKey::fromUrl($url);
    }

    /**
     * Seeds a confirmable row whose `match` payload is malformed-but-array — exactly the hand-edited
     * file-store JSON / older-schema row {@see StoredMatch::fromArray()} accepts (it only asserts the
     * payload is an array, never validating its inner keys). The raw row is round-tripped through
     * {@see StoredMatch::fromArray()} (the same untrusted-JSON boundary the store uses on read), so the
     * malformed shape survives into {@see ReviewScreenHandler::applyConfirm()} unchanged.
     *
     * @param string               $xref  The candidate identifier.
     * @param array<string, mixed> $match The malformed-but-array match payload to persist verbatim.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedMalformedConfirmableMatch(string $xref, array $match): string
    {
        $url = 'https://trauer.example/' . $xref;

        $row = StoredMatch::fromArray([
            'personId'    => $xref,
            'obituaryUrl' => $url,
            'status'      => MatchStatus::Pending->value,
            'match'       => $match,
            'reason'      => null,
            'writeBack'   => null,
        ]);

        $this->store()->upsertPending($row);

        return StoredMatchKey::fromUrl($url);
    }

    /**
     * POST confirm against a malformed-but-array `match` row that is MISSING the `hardConflict` key
     * (an older-schema / hand-edited file-store JSON row) must not 500: the raw `$row->match['hardConflict']`
     * read would otherwise raise an Undefined-array-key warning that webtrees' ErrorHandler turns into a
     * thrown ErrorException — escaping applyConfirm's narrow write-exception catch. The handler narrows
     * the read like the view model (`(… ?? null) === true`), so an absent key reads as "no hard conflict"
     * and the gate evaluates cleanly. With I2 carrying no death date and an exact ISO date present the
     * gate then PASSES, so the confirm completes (a success redirect to the individual, the DEAT written)
     * — the point being a clean outcome instead of a 500 on the missing key.
     *
     * @return void
     */
    #[Test]
    public function postConfirmOnMalformedRowMissingHardConflictDoesNotCrash(): void
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        // A malformed-but-array payload built as a raw map (NOT ClassifiedMatch::emptyArray, whose typed
        // shape PHPStan would not let us de-key): the `hardConflict` key is absent and an exact ISO death
        // date is present, so the GET render would show an enabled Confirm button.
        $match = [
            'personId'       => 'I2',
            'obituaryUrl'    => 'https://trauer.example/I2',
            'extractedFacts' => ['deathDate' => '2023-09-04'],
        ];

        $key = $this->seedMalformedConfirmableMatch('I2', $match);

        // The missing-key read no longer raises a notice (which webtrees would throw as an ErrorException
        // escaping the write-only catch → 500): the confirm runs to a clean success redirect …
        $this->postConfirmAndAssertSuccessRedirect('I2', $key);

        // … and the narrowed gate let the confirm complete: the row is Confirmed and one DEAT was written.
        self::assertSame(MatchStatus::Confirmed, $this->store()->findOne('I2', $key)?->status);

        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(1, iterator_to_array($individual->facts(['DEAT'], false, null, true)));
    }

    /**
     * POST confirm against a malformed-but-array `match` row whose `extractedFacts` is NOT an array (and
     * whose `deathDate` is therefore unreadable) must not 500 either: the raw chained array access
     * `$row->match['extractedFacts']['deathDate']` would warn/throw. The handler narrows `extractedFacts`
     * to an array and `deathDate` to a string exactly as the view model does, so the gate denies on the
     * absent exact date and the confirm degrades to a warning flash with no write and no transition.
     *
     * @return void
     */
    #[Test]
    public function postConfirmOnMalformedRowWithNonArrayExtractedFactsDoesNotCrash(): void
    {
        // extractedFacts as a scalar (not an array) and hardConflict a valid bool: the chained
        // ['extractedFacts']['deathDate'] read is the crash site without the array narrowing.
        $match = [
            'personId'       => 'I2',
            'obituaryUrl'    => 'https://trauer.example/I2',
            'hardConflict'   => false,
            'extractedFacts' => 'not-an-array',
        ];

        $key = $this->seedMalformedConfirmableMatch('I2', $match);

        // The chained read no longer warns/throws (a 500): the gate denies on the unreadable date and the
        // confirm degrades to a warning flash, the row stays pending and no DEAT is written to the tree.
        $this->assertConfirmRefusedNoTransition('I2', $key);

        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(0, iterator_to_array($individual->facts(['DEAT'], false, null, true)));
    }

    /**
     * POST confirm against a malformed-but-array `match` row whose `extractedFacts` IS an array but whose
     * `deathDate` is a non-string (e.g. a number from a hand-edited or older-schema row): the
     * `is_string($isoRaw)` narrowing coerces it to null, the gate denies on the unreadable exact date, and
     * the confirm degrades to a warning flash with no write and no transition. This pins the `is_string`
     * branch so a future refactor that dropped it (passing a non-string straight to the gate/converter,
     * typed `string|null`) would go red on the TypeError instead of regressing to a 500.
     *
     * @return void
     */
    #[Test]
    public function postConfirmOnMalformedRowWithNonStringDeathDateDoesNotCrash(): void
    {
        // extractedFacts is a valid array but deathDate is an int, so only the is_string() narrowing
        // (not the is_array() one) stands between the chained read and the typed gate/converter.
        $match = [
            'personId'       => 'I2',
            'obituaryUrl'    => 'https://trauer.example/I2',
            'hardConflict'   => false,
            'extractedFacts' => ['deathDate' => 12345],
        ];

        $key = $this->seedMalformedConfirmableMatch('I2', $match);

        // The non-string date narrows to null → the gate denies (no exact death date) → warning flash, the
        // row stays pending and no DEAT is written to the tree.
        $this->assertConfirmRefusedNoTransition('I2', $key);

        $individual = $this->individual('I2', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(0, iterator_to_array($individual->facts(['DEAT'], false, null, true)));
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
     * @param string   $xref      The candidate identifier.
     * @param int|null $birthYear The runner-up birth year, or null to exercise the place-only branch.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedPendingMatchWithRunnerUp(string $xref, ?int $birthYear = 1940): string
    {
        $obituaryUrl         = 'https://trauer.example/' . $xref;
        $payload             = ClassifiedMatch::emptyArray($xref, $obituaryUrl);
        $payload['runnerUp'] = [
            'personId'       => 'I2',
            'score'          => 74,
            'classification' => 'probable',
            'name'           => 'Karl Vorbild',
            'birthYear'      => $birthYear,
            'birthPlace'     => 'Beispieldorf',
        ];

        $this->store()->upsertPending(new StoredMatch($xref, $obituaryUrl, MatchStatus::Pending, $payload));

        return StoredMatchKey::fromUrl($obituaryUrl);
    }

    /**
     * Upserts a pending row carrying a single extracted obituary fact so the review screen's
     * fact-label rendering can be exercised end-to-end. The {@see MatchSeeder} only writes a death
     * date, so the payload is tailored here to carry an arbitrary producer key/value pair.
     *
     * @param string $xref      The candidate identifier.
     * @param string $factKey   The extracted-fact key the producer emits.
     * @param string $factValue The extracted-fact value.
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedPendingMatchWithExtractedFact(string $xref, string $factKey, string $factValue): string
    {
        $obituaryUrl               = 'https://trauer.example/' . $xref;
        $payload                   = ClassifiedMatch::emptyArray($xref, $obituaryUrl);
        $payload['extractedFacts'] = [$factKey => $factValue];

        $this->store()->upsertPending(new StoredMatch($xref, $obituaryUrl, MatchStatus::Pending, $payload));

        return StoredMatchKey::fromUrl($obituaryUrl);
    }

    /**
     * Upserts a pending row carrying a single conflict reason so the review screen's conflict
     * field-label rendering can be exercised end-to-end. The conflict reasons live under the dedicated
     * `signals.conflicts.reasons` entry exactly as {@see \MagicSunday\ObituaryMatcher\Domain\MatchExplanation::toArray()}
     * writes them, so the seeded shape mirrors the real payload the producer emits.
     *
     * @param string $xref     The candidate identifier.
     * @param string $field    The conflict field name the detector emits (e.g. 'death date').
     * @param string $severity The conflict severity value ('hard' or 'soft').
     *
     * @return string The canonical row key for the seeded row.
     */
    private function seedPendingMatchWithConflict(string $xref, string $field, string $severity): string
    {
        $obituaryUrl                     = 'https://trauer.example/' . $xref;
        $payload                         = ClassifiedMatch::emptyArray($xref, $obituaryUrl);
        $payload['signals']['conflicts'] = [
            'score'   => -30,
            'reasons' => [
                [
                    'field'         => $field,
                    'treeValue'     => '1901',
                    'obituaryValue' => '1950',
                    'severity'      => $severity,
                ],
            ],
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
