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
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Matching\CoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileCoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\RetryRowView;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\WorklistPresenter;
use MagicSunday\ObituaryMatcher\Ui\WorklistRowView;
use MagicSunday\ObituaryMatcher\Ui\WorklistView;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateCriteria;
use MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWorklistHandler;
use MagicSunday\ObituaryMatcher\Webtrees\RevertConsistencyGate;
use MagicSunday\ObituaryMatcher\Webtrees\RevertOutcome;
use MagicSunday\ObituaryMatcher\Webtrees\RevertReason;
use MagicSunday\ObituaryMatcher\Webtrees\RevertService;
use MagicSunday\ObituaryMatcher\Webtrees\ReviewScreenHandler;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackReverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

use function file_get_contents;
use function file_put_contents;
use function hash;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function view;

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
#[UsesClass(RetryRowView::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\SearchOutcome::class)]
#[UsesClass(PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\CoverageMerge::class)]
#[UsesClass(FileCoverageStore::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\CoverageStoreFactory::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\AtomicFile::class)]
#[UsesClass(BandKey::class)]
#[UsesClass(ObituaryDateFormatter::class)]
#[UsesClass(SourceLink::class)]
#[UsesClass(RevertService::class)]
#[UsesClass(RevertOutcome::class)]
#[UsesClass(RevertReason::class)]
#[UsesClass(RevertConsistencyGate::class)]
#[UsesClass(WriteBackReverter::class)]
#[UsesClass(CandidateRepository::class)]
#[UsesClass(CandidateCriteria::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Ui\CandidateFilterView::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\PersonCandidateAdapter::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\CallNameParser::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PersonCandidate::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PersonName::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DateRange::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DateValue::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DatePrecision::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\Gender::class)]
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
            // Two candidate individuals (#63): born 1900 with no death date, so they are always old
            // enough (age > 120 at any wall-clock year) and stay searchable candidates. They carry no
            // stored match, so they never add a worklist ROW — only the candidate-count preview sees them.
            . "0 @I5@ INDI\n1 NAME Alt /Kandidat/\n1 SEX M\n1 BIRT\n2 DATE 1 JAN 1900\n"
            . "0 @I6@ INDI\n1 NAME Greta /Kandidatin/\n1 SEX F\n1 BIRT\n2 DATE 2 FEB 1900\n"
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
     * The status-filter bar renders one link per filter key (all/open/confirmed/rejected/uncertain),
     * each carrying only its own `status` query parameter (and NO `page`, so switching filter lands on
     * page one).
     *
     * @return void
     */
    #[Test]
    public function statusFilterBarRendersAllFiveFilterLinks(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        foreach (['all', 'open', 'confirmed', 'rejected', 'uncertain'] as $key) {
            self::assertStringContainsString('status=' . $key, $html);
        }

        // A filter link never pins a page, so switching filter always resets to page one.
        self::assertStringNotContainsString('status=open&amp;page=', $html);
    }

    /**
     * The candidate-selection filter form (#63) renders on the worklist with its age-window controls, but
     * a plain render (no preview request) computes and shows no count — the population-hydrating count is
     * paid only on an explicit preview.
     *
     * @return void
     */
    #[Test]
    public function theWorklistRendersTheCandidateSelectionFilter(): void
    {
        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('class="om-candidate-filter"', $html);
        self::assertStringContainsString('name="cand_min_age"', $html);
        self::assertStringContainsString('name="cand_max_age"', $html);
        self::assertStringContainsString('name="cand_unknown_birth"', $html);
        // No preview was requested, so the count line (its own class) is absent — the loose "would be
        // searched" phrase also lives in the form heading, so the count marker is what discriminates.
        self::assertStringNotContainsString('om-candidate-count', $html);
    }

    /**
     * A person whose last search left a portal outage (a `PortalFailed` outcome — ≥1 failed portal, no
     * notices, so no match row) is surfaced in the worklist's "repeat search needed" section (§6.4 point 2),
     * linked to their individual page. This is the tree-wide half of the §5.2c coverage distinction.
     *
     * @return void
     */
    #[Test]
    public function aPortalOutagePersonWithNoMatchIsListedInTheRetrySection(): void
    {
        $this->coverageStore()->record('I2', CoverageStore::DEFAULT_FINDER_ID, [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, 'timeout'),
        ]);

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('class="om-retry-needed"', $html);
        // Emma /Ortlos/ has no stored match, so her name can only appear via the retry surface.
        self::assertStringContainsString('Ortlos', $html);
    }

    /**
     * A cleanly-searched person (all portals `ok`, no notices — a `NoNotices` genuine miss) is NOT surfaced
     * in the retry section: the search was complete, so there is nothing to retry. Only `PortalFailed`
     * people are actionable there.
     *
     * @return void
     */
    #[Test]
    public function aCleanlySearchedPersonIsNotInTheRetrySection(): void
    {
        $this->coverageStore()->record('I3', CoverageStore::DEFAULT_FINDER_ID, [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 0, null),
        ]);

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        // No PortalFailed person → the section is hidden entirely, and Karl /Beispiel/ (no match) never shows.
        self::assertStringNotContainsString('class="om-retry-needed"', $html);
        self::assertStringNotContainsString('Beispiel', $html);
    }

    /**
     * The retry section renders even when the tree has ZERO stored matches: a portal outage with no notice
     * is exactly the case the surface exists for (searched, portal down, nothing stored), so it must show
     * alongside the empty-match state rather than being hidden by it.
     *
     * @return void
     */
    #[Test]
    public function theRetrySectionRendersEvenWithZeroMatches(): void
    {
        $this->coverageStore()->record('I2', CoverageStore::DEFAULT_FINDER_ID, [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, null),
        ]);

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        // Both the empty-match notice AND the retry section render together.
        self::assertStringContainsString('om-worklist-empty', $html);
        self::assertStringContainsString('class="om-retry-needed"', $html);
        self::assertStringContainsString('Ortlos', $html);
    }

    /**
     * A portal-outage record for a person who no longer exists in the tree is skipped (stale), exactly like
     * the match loop — the retry surface never renders a broken link.
     *
     * @return void
     */
    #[Test]
    public function aStalePortalOutagePersonIsSkipped(): void
    {
        $this->coverageStore()->record('IGHOST', CoverageStore::DEFAULT_FINDER_ID, [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, null),
        ]);

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        // IGHOST resolves to no individual → dropped, so the section stays hidden.
        self::assertStringNotContainsString('class="om-retry-needed"', $html);
    }

    /**
     * When the outage set exceeds the render cap, the template shows the capped head plus an "and N more"
     * note driven by the pre-cap total — the render bound never hides how many people actually need a
     * repeat search. Rendered directly from a hand-built view so the cap can be exceeded without seeding
     * fifty-plus individuals.
     *
     * @return void
     */
    #[Test]
    public function theRetrySectionRendersAnAndMoreNoteWhenTruncated(): void
    {
        $view = new WorklistView(
            [],
            ['total' => 0, 'open' => 0, 'confirmed' => 0, 'rejected' => 0, 'uncertain' => 0],
            'all',
            'all',
            'score',
            1,
            1,
            0,
            false,
            [
                new RetryRowView('Anna Beispiel', 'I1', '/p/I1'),
                new RetryRowView('Bert Beispiel', 'I2', '/p/I2'),
            ],
            5,
        );

        // The template builds route() URLs, which resolve the current request from the container — set it
        // here (the handler path has it from middleware) so the direct render can build links.
        Registry::container()->set(ServerRequestInterface::class, $this->worklistRequest(Auth::user()));

        $html = view(self::MODULE_NAMESPACE . '::worklist', [
            'title'           => 'Worklist',
            'tree'            => $this->tree,
            'view'            => $view,
            'candidateFilter' => new \MagicSunday\ObituaryMatcher\Ui\CandidateFilterView(80, null, false, null, false),
        ]);

        self::assertStringContainsString('class="om-retry-needed"', $html);
        self::assertStringContainsString('om-retry-needed-more', $html);
        // Two rendered of five total → the note reports the exact remainder "and 3 more".
        self::assertStringContainsString('and 3 more', $html);
        // The capped head is rendered too (not just the note).
        self::assertStringContainsString('Anna Beispiel', $html);
    }

    /**
     * A preview request (the `cand_preview` marker present) runs the count through the candidate
     * repository and renders the "≈ N people would be searched" line reflecting it.
     *
     * @return void
     */
    #[Test]
    public function aCandidatePreviewRendersTheMatchCount(): void
    {
        // The fixture has exactly two death-date-less individuals old enough to search (I5 + I6, born
        // 1900); I1 has a death date, I2/I3/IHTML have no qualifying birth. So the preview counts 2.
        $html = (string) $this->handler()
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80']))
            ->getBody();

        self::assertStringContainsString('class="om-candidate-count"', $html);
        self::assertStringContainsString('2 people would be searched', $html);
    }

    /**
     * The preview honours the maxAge upper bound end to end (proving the handler wires cand_max_age into
     * the criteria): a max of 100 excludes the age-126 I5/I6 (empty window → 0), while an explicit max of
     * 0 is the "no upper bound" sentinel (0 = off, NOT exclude-all), so the two candidates return.
     *
     * @return void
     */
    #[Test]
    public function thePreviewHonoursTheMaxAgeWindow(): void
    {
        $bounded = (string) $this->handler()
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80', 'cand_max_age' => '100']))
            ->getBody();

        self::assertStringContainsString('0 people would be searched', $bounded);

        $zeroMax = (string) $this->handler()
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80', 'cand_max_age' => '0']))
            ->getBody();

        self::assertStringContainsString('2 people would be searched', $zeroMax);
    }

    /**
     * The preview honours the include-unknown-birth toggle: off (default), only the two born-1900
     * candidates count; on, the three death-date-less, birth-date-less individuals (I2/I3/IHTML) also
     * surface — proving the handler wires cand_unknown_birth into the criteria.
     *
     * @return void
     */
    #[Test]
    public function thePreviewHonoursTheUnknownBirthToggle(): void
    {
        $off = (string) $this->handler()
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80']))
            ->getBody();

        self::assertStringContainsString('2 people would be searched', $off);

        $on = (string) $this->handler()
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80', 'cand_unknown_birth' => '1']))
            ->getBody();

        self::assertStringContainsString('5 people would be searched', $on);
    }

    /**
     * When the eligible population reaches the defensive cap, the preview renders a lower bound ("N+")
     * rather than draining the whole tree: with the cap forced to 1, the two born-1900 candidates are
     * counted only up to 1, and the preview reports "1+" (#63, Gemini hardening).
     *
     * @return void
     */
    #[Test]
    public function aPreviewOverTheCapRendersALowerBound(): void
    {
        $html = (string) $this->cappedHandler(1)
            ->handle($this->worklistRequestWithQuery(Auth::user(), ['cand_preview' => '1', 'cand_min_age' => '80']))
            ->getBody();

        self::assertStringContainsString('1+ person would be searched', $html);
    }

    /**
     * A revert POST on a Confirmed row whose write-back resolves deletes the fact and returns the row to
     * Pending, with a success flash. The DEAT id comes from the fixture purely to exercise the HTTP
     * orchestration end-to-end; the realistic module-WRITTEN confirm→revert fact lifecycle is covered by
     * {@see WriteBackReverterTest} and {@see RevertFlowTest}.
     *
     * @return void
     */
    #[Test]
    public function revertReturnsAResolvableConfirmedRowToPending(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed('I1', $match->obituaryUrl, new WriteBack($this->deatFactIdOfI1(), '@S1@', true));

        $response = $this->postRevertForI1($match->obituaryUrl);

        // PRG redirect back to the worklist.
        self::assertSame(302, $response->getStatusCode());

        $row = $this->reloadI1Row($match->obituaryUrl);
        self::assertSame(MatchStatus::Pending, $row->status);

        self::assertTrue($this->flashContains('success'));

        // The recorded DEAT fact is actually gone from the tree, not just the store status flipped.
        $individual = Registry::individualFactory()->make('I1', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertCount(0, iterator_to_array($individual->facts(['DEAT'], false, null, true)));
    }

    /**
     * A revert POST on a Confirmed row whose recorded fact no longer resolves (edited/removed) refuses:
     * the row stays Confirmed and a danger flash is shown.
     *
     * @return void
     */
    #[Test]
    public function revertRefusesWhenTheRecordedFactNoLongerResolves(): void
    {
        // seedConfirmed records a synthetic '@F1@' write-back that does not resolve on the real individual.
        $this->seedConfirmed('I1');
        $url = $this->store()->findByPerson('I1')[0]->obituaryUrl;

        $response = $this->postRevertForI1($url);

        self::assertSame(302, $response->getStatusCode());

        $row = $this->reloadI1Row($url);
        self::assertSame(MatchStatus::Confirmed, $row->status);

        self::assertTrue($this->flashContains('danger'));
    }

    /**
     * A revert POST on a non-Confirmed row is a benign no-op: a warning flash, the row unchanged, the
     * RevertService never reached (a Pending row carries no write-back to undo).
     *
     * @return void
     */
    #[Test]
    public function revertOnANonConfirmedRowIsANoOpWarning(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $response = $this->postRevertForI1($match->obituaryUrl);

        self::assertSame(302, $response->getStatusCode());

        $row = $this->reloadI1Row($match->obituaryUrl);
        self::assertSame(MatchStatus::Pending, $row->status);

        self::assertTrue($this->flashContains('warning'));
    }

    /**
     * A non-manager POST is denied by the same gate as the GET path.
     *
     * @return void
     */
    #[Test]
    public function nonManagerRevertIsDenied(): void
    {
        $this->expectException(HttpAccessDeniedException::class);

        $this->handler()->handle($this->revertRequest(new GuestUser(), [
            'action' => 'revert',
            'person' => 'I1',
            'url'    => 'https://example.test/notice',
        ]));
    }

    /**
     * An unknown POST action is rejected as a bad request.
     *
     * @return void
     */
    #[Test]
    public function unknownPostActionIsRejected(): void
    {
        $this->expectException(HttpBadRequestException::class);

        $this->handler()->handle($this->revertRequest(Auth::user(), [
            'action' => 'frobnicate',
            'person' => 'I1',
            'url'    => 'https://example.test/notice',
        ]));
    }

    /**
     * Bulk-reject rejects every selected OPEN row in one action (#65): a Pending and an Uncertain row
     * both become Rejected, with a single success flash.
     *
     * @return void
     */
    #[Test]
    public function bulkRejectRejectsSelectedOpenRows(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        MatchSeeder::seed($this->store(), 'I2', MatchStatus::Uncertain, 'weak', '2023-09-04');

        $response = $this->handler()->handle($this->revertRequest(Auth::user(), [
            'action'   => 'bulk-reject',
            'selected' => [$this->bulkToken('I1'), $this->bulkToken('I2')],
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(MatchStatus::Rejected, $this->reloadRow('I1')->status);
        self::assertSame(MatchStatus::Rejected, $this->reloadRow('I2')->status);

        $messages = FlashMessages::getMessages();
        self::assertCount(1, $messages);
        self::assertSame('success', $messages[0]->status);
    }

    /**
     * Bulk-reject skips a Confirmed row: it is terminal and carries a write-back a reject must not
     * silently strand (undoing it is the separate Revert action), so it stays Confirmed and nothing is
     * rejected (a warning flash).
     *
     * @return void
     */
    #[Test]
    public function bulkRejectSkipsAConfirmedRow(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed('I1', $match->obituaryUrl, new WriteBack($this->deatFactIdOfI1(), '@S1@', true));

        $response = $this->handler()->handle($this->revertRequest(Auth::user(), [
            'action'   => 'bulk-reject',
            'selected' => [$this->bulkToken('I1')],
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(MatchStatus::Confirmed, $this->reloadRow('I1')->status);
        self::assertSame('warning', FlashMessages::getMessages()[0]->status);
    }

    /**
     * A malformed selection token (no colon, or a key that resolves no row) is skipped without aborting
     * the batch: nothing is rejected and the open row is untouched.
     *
     * @return void
     */
    #[Test]
    public function bulkRejectSkipsAnInvalidToken(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $response = $this->handler()->handle($this->revertRequest(Auth::user(), [
            'action'   => 'bulk-reject',
            'selected' => ['garbage-no-colon', 'I1:deadbeefnotarealrowkey'],
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(MatchStatus::Pending, $this->reloadRow('I1')->status);
        self::assertSame('warning', FlashMessages::getMessages()[0]->status);
    }

    /**
     * A non-string selection element (a crafted nested `selected[0][]=x` body) is handled safely — never
     * a 500 — regardless of the webtrees version: a stricter `Validator::array` rejects the nested element
     * upstream with a 400 `HttpBadRequestException`, while a laxer one passes it through, where parsing
     * inside the per-row try/catch absorbs the `explode()` TypeError (302 + warning). Either way the open
     * row is untouched and no TypeError escapes as a 500.
     *
     * @return void
     */
    #[Test]
    public function bulkRejectSkipsANonStringToken(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        try {
            $response = $this->handler()->handle($this->revertRequest(Auth::user(), [
                'action'   => 'bulk-reject',
                'selected' => [['nested']],
            ]));

            // Laxer Validator: the handler absorbed the non-string token and redirected with a warning.
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('warning', FlashMessages::getMessages()[0]->status);
        } catch (HttpBadRequestException $exception) {
            // Stricter Validator: the nested element is rejected upstream as a 400 — also safe (not a 500).
            self::assertStringContainsStringIgnoringCase('selected', $exception->getMessage());
        }

        // The invariant that matters across both paths: the open row is never rejected, and no uncaught
        // TypeError (a 500) escaped — a 500 would surface as a TypeError, not the caught HttpBadRequest.
        self::assertSame(MatchStatus::Pending, $this->reloadRow('I1')->status);
    }

    /**
     * A row that races to a terminal state between the read and the write is absorbed: markRejected is
     * status-guarded and throws, and bulk-reject must skip that row + PRG-redirect rather than escape as a
     * 500. Driven by a store whose markRejected always throws, standing in for the concurrent transition.
     *
     * @return void
     */
    #[Test]
    public function bulkRejectSurvivesAConcurrentTerminalTransition(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $response = $this->handlerWithRacingStore()->handle($this->revertRequest(Auth::user(), [
            'action'   => 'bulk-reject',
            'selected' => [$this->bulkToken('I1')],
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('warning', FlashMessages::getMessages()[0]->status);
        // The racing store's markRejected threw before writing, so the real row stays Pending.
        self::assertSame(MatchStatus::Pending, $this->reloadRow('I1')->status);
    }

    /**
     * The worklist renders the #65 controls: the quality-flag filter pills, the sort links, and the
     * bulk-reject form with a selection checkbox (via the HTML5 `form` attribute) on the open row.
     *
     * @return void
     */
    #[Test]
    public function theWorklistRendersTheFilterSortAndBulkRejectControls(): void
    {
        MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('om-worklist-flags', $html);
        self::assertStringContainsString('om-worklist-sort', $html);
        self::assertStringContainsString('id="om-bulk-reject"', $html);
        self::assertStringContainsString('name="selected[]"', $html);
        self::assertStringContainsString('form="om-bulk-reject"', $html);
        // The status/flag/sort links carry the other active params, so switching one keeps the others
        // (the pagination links do the same).
        self::assertStringContainsString('flag=all', $html);
        self::assertStringContainsString('sort=score', $html);
    }

    /**
     * A Confirmed row renders a revert POST form carrying the CSRF token and the revert action/person/url
     * hidden fields, so the button is wired to the handler's POST branch.
     *
     * @return void
     */
    #[Test]
    public function confirmedRowRendersACsrfProtectedRevertForm(): void
    {
        $this->seedConfirmed('I1');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('value="revert"', $html);
        self::assertStringContainsString('name="person"', $html);
        self::assertStringContainsString('name="url"', $html);
    }

    /**
     * The Confirmed row's revert form carries an accessible per-person aria-label (the action column header
     * is visually hidden, so a screen reader would otherwise hear a column of identical "Revert" buttons)
     * and is a plain POST with NO JS confirmation dialog: the approved spec excludes the confirm step
     * (symmetry with the review screen's plain forms; the revert is reversible by re-confirming). This test
     * reds if either the aria-label is dropped or an `onsubmit`/`confirm(` JS dialog is re-introduced.
     *
     * @return void
     */
    #[Test]
    public function revertFormHasNoJsConfirmAndCarriesAnAriaLabel(): void
    {
        $this->seedConfirmed('I1');

        $html = (string) $this->handler()->handle($this->worklistRequest(Auth::user()))->getBody();

        // The button names the person for assistive technology.
        self::assertStringContainsString('Revert match for', $html);
        // No JS confirmation dialog: the form is a plain POST (approved YAGNI exclusion).
        self::assertStringNotContainsString('onsubmit', $html);
        self::assertStringNotContainsString('confirm(', $html);
    }

    /**
     * A revert POST on a Confirmed row whose on-disk write-back is non-null but malformed redirects with
     * a danger flash and leaves the row Confirmed (it does not 500). The `InvalidWriteBack` reason itself
     * is pinned directly at the service layer by
     * {@see RevertServiceTest::aCorruptWriteBackReportsInvalidWriteBack}.
     *
     * @return void
     */
    #[Test]
    public function revertOnACorruptWriteBackFlashesDanger(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed('I1', $match->obituaryUrl, new WriteBack('@F1@', '@S1@', true));

        // Rewrite the on-disk write-back to a non-null but malformed array (missing deatFactId): it passes
        // the handler's writeBack!==null guard but WriteBack::fromArray rejects it in the service.
        $this->rewriteStoredRowField($match->obituaryUrl, 'writeBack', ['buriFactId' => null]);

        $response = $this->postRevertForI1($match->obituaryUrl);

        self::assertSame(302, $response->getStatusCode());

        $row = $this->reloadI1Row($match->obituaryUrl);
        self::assertSame(MatchStatus::Confirmed, $row->status);
        self::assertTrue($this->flashContains('danger'));
    }

    /**
     * A revert POST against a CORRUPT existing row (unparseable JSON) redirects with a danger flash, NOT a
     * 500: `findOne` is fail-loud (unlike the GET scan), so the wider try/catch must absorb it.
     *
     * @return void
     */
    #[Test]
    public function revertOnACorruptStoredRowFlashesDangerNot500(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed('I1', $match->obituaryUrl, new WriteBack('@F1@', '@S1@', true));

        $path = $this->dir . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($match->obituaryUrl) . '.json';
        file_put_contents($path, 'not valid json{');

        $response = $this->postRevertForI1($match->obituaryUrl);

        self::assertSame(302, $response->getStatusCode());
        self::assertTrue($this->flashContains('danger'));
    }

    /**
     * A revert POST whose found row carries a DIFFERENT internal personId than the posted person is a
     * benign no-op warning (defence-in-depth, mirroring ReviewScreenHandler's cross-row guard).
     *
     * @return void
     */
    #[Test]
    public function revertOnAPersonIdMismatchIsANoOpWarning(): void
    {
        $match = MatchSeeder::seed($this->store(), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');
        $this->store()->markConfirmed('I1', $match->obituaryUrl, new WriteBack('@F1@', '@S1@', true));

        // Hand-corrupt the row's internal personId so it no longer matches the directory it sits in.
        $this->rewriteStoredRowField($match->obituaryUrl, 'personId', 'I2');

        $response = $this->postRevertForI1($match->obituaryUrl);

        self::assertSame(302, $response->getStatusCode());
        self::assertTrue($this->flashContains('warning'));

        // No mutation: the on-disk row stays Confirmed (the guard refused before any write-back undo).
        $row = $this->reloadI1Row($match->obituaryUrl);
        self::assertSame(MatchStatus::Confirmed, $row->status);
    }

    /**
     * Whether a flash message of the given status was queued by the handler.
     *
     * @param string $status The bootstrap flash status (success/warning/danger).
     *
     * @return bool True when a message of that status is queued.
     */
    private function flashContains(string $status): bool
    {
        foreach (FlashMessages::getMessages() as $message) {
            if ($message->status === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * Posts a revert action for person I1 against the given obituary URL and returns the handler's response.
     *
     * @param string $url The obituary URL of the row to revert.
     *
     * @return ResponseInterface The handler's response (a PRG redirect on the revert branch).
     */
    private function postRevertForI1(string $url): ResponseInterface
    {
        return $this->handler()->handle($this->revertRequest(Auth::user(), [
            'action' => 'revert',
            'person' => 'I1',
            'url'    => $url,
        ]));
    }

    /**
     * Reloads person I1's stored row for the given obituary URL, asserting it still resolves.
     *
     * @param string $url The obituary URL identifying the row to reload.
     *
     * @return StoredMatch The reloaded stored row.
     */
    private function reloadI1Row(string $url): StoredMatch
    {
        $row = $this->store()->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $row);

        return $row;
    }

    /**
     * Builds the `personId:rowKey` bulk-reject selection token for the given seeded xref (#65), matching
     * the token the worklist template renders on the checkbox.
     *
     * @param string $xref The seeded person xref.
     *
     * @return string The selection token.
     */
    private function bulkToken(string $xref): string
    {
        return $xref . ':' . StoredMatchKey::fromUrl('https://trauer.example/' . $xref);
    }

    /**
     * A worklist handler whose store's markRejected always throws (standing in for a row that raced to a
     * terminal state between the read and the write), so the bulk-reject batch's resilience can be
     * asserted without a real concurrency window.
     *
     * @return ObituaryWorklistHandler The test-wired handler.
     */
    private function handlerWithRacingStore(): ObituaryWorklistHandler
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
                // FileMatchStore is final, so wrap it in a decorator that delegates every read/write to
                // the real store EXCEPT markRejected, which throws — standing in for a row that raced to a
                // terminal state between the batch's read and its write.
                return new class(new FileMatchStore($this->storeDir)) implements MatchStore {
                    public function __construct(private readonly MatchStore $inner)
                    {
                    }

                    public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
                    {
                        throw new TerminalMatchTransitionException('raced to a terminal state');
                    }

                    public function upsertPending(StoredMatch $match): bool
                    {
                        return $this->inner->upsertPending($match);
                    }

                    /**
                     * @return list<StoredMatch>
                     */
                    public function findByPerson(string $personId): array
                    {
                        return $this->inner->findByPerson($personId);
                    }

                    /**
                     * @return list<StoredMatch>
                     */
                    public function allPending(): array
                    {
                        return $this->inner->allPending();
                    }

                    /**
                     * @return list<StoredMatch>
                     */
                    public function all(): array
                    {
                        return $this->inner->all();
                    }

                    public function findOne(string $personId, string $rowKey): ?StoredMatch
                    {
                        return $this->inner->findOne($personId, $rowKey);
                    }

                    public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
                    {
                        $this->inner->markUncertain($personId, $obituaryUrl, $reason);
                    }

                    public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
                    {
                        return $this->inner->markConfirmed($personId, $obituaryUrl, $writeBack);
                    }

                    public function revert(string $personId, string $obituaryUrl): void
                    {
                        $this->inner->revert($personId, $obituaryUrl);
                    }
                };
            }
        };
    }

    /**
     * Reloads a seeded person's stored row by xref (the seeder uses a deterministic obituary URL).
     *
     * @param string $xref The seeded person xref.
     *
     * @return StoredMatch The reloaded row.
     */
    private function reloadRow(string $xref): StoredMatch
    {
        $row = $this->store()->findOne($xref, StoredMatchKey::fromUrl('https://trauer.example/' . $xref));
        self::assertInstanceOf(StoredMatch::class, $row);

        return $row;
    }

    /**
     * Hand-rewrites a single field of person I1's on-disk stored row, used to inject a corrupt value the
     * normal store API cannot produce.
     *
     * @param string                      $url   The obituary URL identifying the row file.
     * @param string                      $field The top-level JSON field to overwrite.
     * @param array<string, mixed>|string $value The value to assign to the field.
     *
     * @return void
     */
    private function rewriteStoredRowField(string $url, string $field, array|string $value): void
    {
        $path = $this->dir . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json';

        /** @var array<string, mixed> $data */
        $data         = json_decode((string) file_get_contents($path), true);
        $data[$field] = $value;
        file_put_contents($path, json_encode($data));
    }

    /**
     * Builds a POST request for the worklist route authenticated as the given user, carrying the parsed
     * body exactly as webtrees' middleware would after CSRF validation.
     *
     * @param UserInterface        $user The user attached as the request's `user` attribute.
     * @param array<string, mixed> $body The parsed POST body (a value may be a scalar, the bulk-reject
     *                                   `selected[]` list, or — for a hardening test — a nested array).
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function revertRequest(UserInterface $user, array $body): ServerRequestInterface
    {
        $factory = Registry::container()->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);

        $route = new Route();
        $route->name(ObituaryWorklistHandler::ROUTE_NAME);

        $request = $factory
            ->createServerRequest(RequestMethodInterface::METHOD_POST, 'https://webtrees.test/index.php')
            ->withParsedBody($body)
            ->withAttribute('base_url', 'https://webtrees.test')
            ->withAttribute('client-ip', '127.0.0.1')
            ->withAttribute('route', $route)
            ->withAttribute('tree', $this->tree)
            ->withAttribute('user', $user);

        Registry::container()->set(Tree::class, $this->tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }

    /**
     * The captured fact id of I1's fixture DEAT fact (id = md5 of the fact gedcom), so a confirmed row's
     * write-back resolves against the live individual.
     *
     * @return string The DEAT fact id.
     */
    private function deatFactIdOfI1(): string
    {
        $individual = Registry::individualFactory()->make('I1', $this->tree);
        self::assertInstanceOf(Individual::class, $individual);

        $fact = $individual->facts(['DEAT'], false, null, true)->first();
        self::assertInstanceOf(Fact::class, $fact);

        return $fact->id();
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

            protected function coverageStoreForTree(Tree $tree): CoverageStore
            {
                return new FileCoverageStore($this->storeDir . '/__coverage');
            }
        };
    }

    /**
     * Returns the temp-directory coverage store the retry-surface tests seed portal-outage records into.
     * A separate namespace under the match store dir (its own sha256(personId) sub-tree), so a coverage
     * document never collides with a match row.
     *
     * @return CoverageStore The temp-directory coverage store.
     */
    private function coverageStore(): CoverageStore
    {
        return new FileCoverageStore($this->dir . '/__coverage');
    }

    /**
     * A worklist handler whose candidate-count preview cap is forced to the given value, so the "N+"
     * over-cap rendering can be asserted without seeding a cap-sized candidate population.
     *
     * @param int $cap The forced preview count cap.
     *
     * @return ObituaryWorklistHandler The test-wired handler.
     */
    private function cappedHandler(int $cap): ObituaryWorklistHandler
    {
        return new class(self::MODULE_NAMESPACE, $this->dir, $cap) extends ObituaryWorklistHandler {
            /**
             * @param string $viewNamespace The view namespace the handler renders under.
             * @param string $storeDir      The temp store directory injected for the test.
             * @param int    $cap           The forced preview count cap.
             */
            public function __construct(string $viewNamespace, private readonly string $storeDir, private readonly int $cap)
            {
                parent::__construct($viewNamespace);
            }

            protected function storeForTree(Tree $tree): MatchStore
            {
                return new FileMatchStore($this->storeDir);
            }

            protected function previewCountCap(): int
            {
                return $this->cap;
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
        return $this->worklistRequestWithQuery($user, []);
    }

    /**
     * Builds a GET request for the worklist route as the given user, carrying the tree + route attribute
     * and the given query params (the candidate-filter form submits via GET query params, #63).
     *
     * @param UserInterface        $user  The user attached as the request's `user` attribute.
     * @param array<string,string> $query The query params to attach.
     *
     * @return ServerRequestInterface The request the handler consumes.
     */
    private function worklistRequestWithQuery(UserInterface $user, array $query): ServerRequestInterface
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
            ->withAttribute('user', $user)
            ->withQueryParams($query);

        Registry::container()->set(Tree::class, $this->tree);
        Registry::container()->set(ServerRequestInterface::class, $request);

        return $request;
    }
}
