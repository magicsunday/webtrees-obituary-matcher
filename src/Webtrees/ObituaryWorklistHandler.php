<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Ui\WorklistPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function in_array;
use function max;
use function route;
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
     * Constructor.
     *
     * @param string $viewNamespace The module's registered view namespace (its {@see \Fisharebest\Webtrees\Module\ModuleCustomInterface::name()}).
     */
    public function __construct(
        private readonly string $viewNamespace,
    ) {
    }

    /**
     * Handles the worklist request. A manager GET renders the tree-wide overview of every stored match.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The rendered worklist screen.
     *
     * @throws HttpAccessDeniedException When the user is not a manager of the tree.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isManager($tree, $user)) {
            throw new HttpAccessDeniedException();
        }

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
        $page   = max(1, Validator::queryParams($request)->integer('page', 1));

        $view = (new WorklistPresenter())->build($entries, $status, $page);

        return $this->viewResponse($this->viewNamespace . '::worklist', [
            'title' => $this->worklistTitle(),
            'tree'  => $tree,
            'view'  => $view,
        ]);
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
