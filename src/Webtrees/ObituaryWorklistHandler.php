<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Ui\CandidateFilterView;
use MagicSunday\ObituaryMatcher\Ui\WorklistPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function count;
use function explode;
use function in_array;
use function max;
use function min;
use function redirect;
use function route;
use function sprintf;
use function strip_tags;

/**
 * The tree-wide worklist route handler. It renders a manager-only, read-only overview of every stored
 * obituary match across all statuses: it loads every store row, resolves each individual (skipping a
 * stale row whose person no longer exists), builds the webtrees-coupled URLs (the internal individual
 * page and, for a non-terminal row, the per-item review screen), reduces the display name to plain
 * text at the boundary, and hands the plain entries to the webtrees-free {@see WorklistPresenter} that
 * filters, sorts, paginates and counts them. Manager access is enforced here because the route is
 * directly callable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class ObituaryWorklistHandler implements RequestHandlerInterface
{
    use ViewResponseTrait;

    /**
     * The route name used to register and to build links to this handler.
     */
    public const string ROUTE_NAME = 'obituary-matcher-worklist';

    /**
     * The route URL pattern.
     */
    public const string ROUTE_URL = '/tree/{tree}/obituary-worklist';

    /**
     * The defensive upper bound on the candidate-count preview (#63): the count stops hydrating at this
     * many candidates and is rendered as "N+", so a preview on a very large tree can never trigger an
     * unbounded hydration walk. A generous ceiling — far above any realistic batch a manager would search
     * at once — so the exact figure still shows for normal trees.
     */
    private const int PREVIEW_COUNT_CAP = 1000;

    /**
     * Constructor.
     *
     * @param string $viewNamespace The module's registered view namespace (its {@see \Fisharebest\Webtrees\Module\ModuleCustomInterface::name()}).
     */
    public function __construct(
        private readonly string $viewNamespace,
    ) {
    }

    /**
     * Handles the worklist request. It gates manager access once, then dispatches on the HTTP method: a
     * GET renders the tree-wide overview of every stored match, a POST applies a per-row revert.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The rendered worklist screen or the PRG redirect after a revert.
     *
     * @throws HttpAccessDeniedException When the user is not a manager of the tree.
     * @throws HttpBadRequestException   When a POST carries an unknown action.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isManager($tree, $user)) {
            throw new HttpAccessDeniedException();
        }

        if ($request->getMethod() === RequestMethodInterface::METHOD_POST) {
            return $this->applyPostAction($request, $tree);
        }

        return $this->renderWorklist($request, $tree);
    }

    /**
     * Renders the manager GET overview of every stored match (filtered, sorted, paginated by the
     * webtrees-free presenter).
     *
     * @param ServerRequestInterface $request The incoming request.
     * @param Tree                   $tree    The tree whose worklist is rendered.
     *
     * @return ResponseInterface The rendered worklist screen.
     */
    private function renderWorklist(ServerRequestInterface $request, Tree $tree): ResponseInterface
    {
        $entries = [];

        foreach ($this->storeForTree($tree)->all() as $row) {
            $individual = Registry::individualFactory()->make($row->personId, $tree);

            // A row whose person no longer exists is stale: skip it from the list and the counts.
            if (!$individual instanceof Individual) {
                continue;
            }

            $entries[] = [
                'match'      => $row,
                'personName' => strip_tags($individual->fullName()),
                'personId'   => $row->personId,
                'personUrl'  => route(IndividualPage::class, [
                    'tree' => $tree->name(),
                    'xref' => $row->personId,
                ]),
                'reviewUrl' => $this->reviewUrl($tree, $row),
            ];
        }

        $status = Validator::queryParams($request)->string('status', 'all');
        $flag   = Validator::queryParams($request)->string('flag', 'all');
        $sort   = Validator::queryParams($request)->string('sort', 'score');
        $page   = max(1, Validator::queryParams($request)->integer('page', 1));

        $view = (new WorklistPresenter())->build($entries, $status, $flag, $sort, $page);

        return $this->viewResponse($this->viewNamespace . '::worklist', [
            'title'           => $this->worklistTitle(),
            'tree'            => $tree,
            'view'            => $view,
            'candidateFilter' => $this->candidateFilterView($request, $tree),
        ]);
    }

    /**
     * Builds the candidate-selection filter view (#63) from the request's query params: the age window
     * (min, optional max) and the include-unknown-birth toggle, each clamped to a sane range. Only when a
     * preview was explicitly requested (the `cand_preview` marker the "Preview count" submit carries) does
     * it run the (population-hydrating) count — a plain worklist render leaves the count null and pays
     * nothing. The count routes through {@see CandidateRepository::countCandidates()}, so it honours the
     * exact privacy gate + age window the real search would: a filter previews WHO would be searched but
     * can never surface a person the privacy gate hides.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @param Tree                   $tree    The tree whose candidates are previewed.
     *
     * @return CandidateFilterView The projected filter state + optional count.
     */
    private function candidateFilterView(ServerRequestInterface $request, Tree $tree): CandidateFilterView
    {
        $params = Validator::queryParams($request);
        $minAge = max(CandidateCriteria::AGE_FLOOR, min(CandidateCriteria::AGE_CEILING, $params->integer('cand_min_age', CandidateCriteria::DEFAULT_MIN_AGE)));
        $maxRaw = max(CandidateCriteria::AGE_FLOOR, min(CandidateCriteria::AGE_CEILING, $params->integer('cand_max_age', 0)));
        // A blank or 0 max means "no upper bound" — 0 is not a meaningful age ceiling here (nobody old
        // enough to search is <= 0), so it is the natural sentinel, and a blank number field arrives as 0
        // indistinguishably. Any positive value is the real ceiling.
        $maxAge  = $maxRaw > 0 ? $maxRaw : null;
        $unknown = $params->boolean('cand_unknown_birth', false);

        $count      = null;
        $reachedCap = false;

        if ($params->boolean('cand_preview', false)) {
            $cap   = $this->previewCountCap();
            $count = $this->candidateRepository()->countCandidates(
                $tree,
                new CandidateCriteria(minAge: $minAge, includeUnknownBirth: $unknown, maxAge: $maxAge),
                $cap,
            );
            $reachedCap = $count >= $cap;
        }

        return new CandidateFilterView($minAge, $maxAge, $unknown, $count, $reachedCap);
    }

    /**
     * The candidate selector used for the "≈ N people match" preview. A protected seam so a test can
     * drive the count over a repository whose tree is an in-memory fixture.
     *
     * @return CandidateRepository The candidate selector.
     */
    protected function candidateRepository(): CandidateRepository
    {
        return new CandidateRepository();
    }

    /**
     * The defensive cap applied to the candidate-count preview. A protected seam so a test can drive the
     * "N+" over-cap rendering with a low cap instead of seeding a thousand-candidate tree.
     *
     * @return int The maximum candidates counted before the preview reports "N+".
     */
    protected function previewCountCap(): int
    {
        return self::PREVIEW_COUNT_CAP;
    }

    /**
     * Dispatches a worklist POST by its `action` body field: `revert` to the single-row write-back revert
     * ({@see revertOne()}), `bulk-reject` to the multi-select batch rejection ({@see bulkReject()}). Both
     * delegate a PRG redirect back to the worklist; an unknown or missing action is a client error answered
     * with 400.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     * @param Tree                   $tree    The tree the POST targets.
     *
     * @return ResponseInterface The delegated PRG redirect response.
     *
     * @throws HttpBadRequestException When the POST action is neither "revert" nor "bulk-reject".
     */
    private function applyPostAction(ServerRequestInterface $request, Tree $tree): ResponseInterface
    {
        return match (Validator::parsedBody($request)->string('action', '')) {
            'revert'      => $this->revertOne($request, $tree),
            'bulk-reject' => $this->bulkReject($request, $tree),
            default       => throw new HttpBadRequestException(),
        };
    }

    /**
     * Reverts a single confirmed match's write-back (the per-row revert action). Always PRG-redirects to
     * the worklist with a flash.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     * @param Tree                   $tree    The tree the row belongs to.
     *
     * @return ResponseInterface The redirect response.
     */
    private function revertOne(ServerRequestInterface $request, Tree $tree): ResponseInterface
    {
        $worklistUrl = route(self::ROUTE_NAME, ['tree' => $tree->name()]);

        $personId = Validator::parsedBody($request)->string('person', '');
        $url      = Validator::parsedBody($request)->string('url', '');

        try {
            $store = $this->storeForTree($tree);
            $row   = $store->findOne($personId, StoredMatchKey::fromUrl($url));

            if (
                (!$row instanceof StoredMatch)
                || ($row->personId !== $personId)
                || ($row->status !== MatchStatus::Confirmed)
                || ($row->writeBack === null)
            ) {
                return $this->warnNotRevertable($worklistUrl);
            }

            $individual = Registry::individualFactory()->make($personId, $tree);

            if (!$individual instanceof Individual) {
                return $this->warnNotRevertable($worklistUrl);
            }

            RevertFlash::flashOutcome((new RevertService())->revert($individual, $row, $store, false));
        } catch (Throwable $throwable) {
            // Always-PRG-no-500: a corrupt row (findOne is fail-loud) or any never-anticipated producer
            // fault still redirects with a generic danger flash rather than escaping as a 500. The fault
            // is logged for diagnostics — but only the person XREF and the exception CLASS, never the raw
            // message (which can embed the absolute store path) nor the obituary URL (S46).
            Log::addErrorLog(sprintf('Obituary matcher: a revert request failed for person %s (%s).', $personId, $throwable::class));
            FlashMessages::addMessage(I18N::translate('The match could not be reverted.'), 'danger');
        }

        return redirect($worklistUrl);
    }

    /**
     * Rejects every selected OPEN match in one action (#65 bulk-reject). Each selected checkbox carries a
     * `personId:rowKey` token; the row is re-resolved from the store (authoritative — the token is
     * untrusted), and only a non-terminal (Pending / Uncertain) row is rejected: a Confirmed row has a
     * write-back a reject must not silently strand (undoing it is the separate Revert action) and a
     * Rejected row is already terminal. A corrupt or unresolvable token is skipped, never aborting the
     * batch. Always PRG-redirects with a count flash.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     * @param Tree                   $tree    The tree the rows belong to.
     *
     * @return ResponseInterface The redirect response.
     */
    private function bulkReject(ServerRequestInterface $request, Tree $tree): ResponseInterface
    {
        $worklistUrl = route(self::ROUTE_NAME, ['tree' => $tree->name()]);
        $store       = $this->storeForTree($tree);
        $rejected    = 0;

        foreach (Validator::parsedBody($request)->array('selected') as $token) {
            try {
                // The token is untrusted: Validator::array() does NOT narrow its elements to strings, so a
                // crafted nested body (selected[0][]=x) yields an array here and explode() would TypeError.
                // Parsing inside the try lets the catch absorb that — along with a corrupt row or a
                // concurrent transition — so one bad selection never aborts the batch or escapes as a 500.
                $parts = explode(':', $token, 2);

                if (count($parts) !== 2) {
                    continue;
                }

                [$personId, $rowKey] = $parts;

                $row = $store->findOne($personId, $rowKey);

                if (!$row instanceof StoredMatch) {
                    continue;
                }

                // Defence-in-depth (mirrors revertOne): keep the write on the exact row that was read, so
                // a misplaced/corrupt store row can never write a tombstone under a mismatched personId.
                if ($row->personId !== $personId) {
                    continue;
                }

                // A Confirmed row has a write-back a reject must not strand (undoing it is the separate
                // Revert action) and a Rejected row is already terminal — both are skipped.
                if ($row->status->isTerminal()) {
                    continue;
                }

                $store->markRejected($personId, $row->obituaryUrl, null);
                ++$rejected;
            } catch (Throwable) {
                // A non-string/corrupt token, an invalid key, OR a concurrent transition that turned the
                // row terminal between the read and the write (markRejected is status-guarded and throws a
                // TerminalMatchTransitionException on a now-Confirmed row) — skip this one, never abort the
                // batch and never escape as a 500.
                continue;
            }
        }

        FlashMessages::addMessage(
            I18N::plural('%s match rejected.', '%s matches rejected.', $rejected, I18N::number($rejected)),
            $rejected > 0 ? 'success' : 'warning',
        );

        return redirect($worklistUrl);
    }

    /**
     * Flashes the generic "not revertable" warning and redirects to the worklist (the shared PRG tail
     * for every benign non-revertable-row guard).
     *
     * @param string $worklistUrl The worklist URL to redirect to.
     *
     * @return ResponseInterface The PRG redirect carrying the warning flash.
     */
    private function warnNotRevertable(string $worklistUrl): ResponseInterface
    {
        RevertFlash::flashNotRevertable();

        return redirect($worklistUrl);
    }

    /**
     * Builds the per-item review URL for a non-terminal row (Pending or Uncertain), or null for a
     * terminal (Confirmed or Rejected) row that is no longer reviewable.
     *
     * @param Tree        $tree The tree the row belongs to.
     * @param StoredMatch $row  The stored row.
     *
     * @return string|null The review URL, or null when the row is terminal.
     */
    private function reviewUrl(Tree $tree, StoredMatch $row): ?string
    {
        if (!in_array($row->status, [MatchStatus::Pending, MatchStatus::Uncertain], true)) {
            return null;
        }

        return route(ReviewScreenHandler::ROUTE_NAME, [
            'tree' => $tree->name(),
            'xref' => $row->personId,
            'key'  => StoredMatchKey::fromUrl($row->obituaryUrl),
        ]);
    }

    /**
     * Returns the tree-scoped match store. The seam lets a test subclass inject a store over a temp
     * directory.
     *
     * @param Tree $tree The tree whose store is requested.
     *
     * @return MatchStore The tree-scoped match store.
     */
    protected function storeForTree(Tree $tree): MatchStore
    {
        return MatchStoreFactory::forTree($tree);
    }

    /**
     * The worklist page title.
     *
     * @return string The translated title.
     */
    private function worklistTitle(): string
    {
        return I18N::translate('Obituary worklist');
    }
}
