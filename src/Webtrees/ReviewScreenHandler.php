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
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function preg_match;
use function strip_tags;

/**
 * The review-screen route handler. It renders a read-only split-view of one stored match (tree
 * person versus obituary, the explainable per-signal score and conflicts). It is a separate handler
 * so the module stays a thin adapter. Manager access is enforced here because the route is directly
 * callable. The reject/uncertain POST dispatch arrives in Phase 2d-2 Task 5; until then a non-GET
 * request is refused.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class ReviewScreenHandler implements RequestHandlerInterface
{
    use ViewResponseTrait;

    /**
     * The route name used to register and to build links to this handler.
     */
    public const string ROUTE_NAME = 'obituary-matcher-review';

    /**
     * The route URL pattern. `{key}` is the canonical row key (SHA-256 hex of the normalised URL).
     */
    public const string ROUTE_URL = '/tree/{tree}/obituary-review/{xref}/{key}';

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
     * Handles the review-screen request. GET renders the screen; a non-GET request is refused until
     * Task 5 adds the review-decision dispatch.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The rendered review screen.
     *
     * @throws HttpNotFoundException     When the key is malformed, the row is absent or terminal, or
     *                                   the method is not GET.
     * @throws HttpAccessDeniedException When the user is not a manager of the tree.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::attributes($request)->isXref()->string('xref');
        $key  = Validator::attributes($request)->string('key');

        // The row key is a SHA-256 hex digest; reject any other shape cheaply and clearly.
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new HttpNotFoundException();
        }

        if (!Auth::isManager($tree, $user)) {
            throw new HttpAccessDeniedException();
        }

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, false);

        $row = $this->resolveRow($tree, $xref, $key);

        // POST handling arrives in Task 5; until then this handler renders GET only. The route is
        // registered with ->allows(POST), so guard non-GET explicitly rather than rendering on POST.
        // (Task 5 replaces this guard with the reject/uncertain dispatch.)
        if ($request->getMethod() !== RequestMethodInterface::METHOD_GET) {
            throw new HttpNotFoundException();
        }

        $vm = ReviewViewModel::fromStoredMatch($row, $this->treePerson($individual));

        return $this->viewResponse($this->viewNamespace . '::review', [
            'title' => $this->reviewTitle(),
            'vm'    => $vm,
            'tree'  => $tree,
            'xref'  => $xref,
            'key'   => $key,
        ]);
    }

    /**
     * Resolves the single non-terminal stored row for the route, or 404s.
     *
     * @param Tree   $tree The tree the row belongs to.
     * @param string $xref The candidate identifier.
     * @param string $key  The canonical row key.
     *
     * @return StoredMatch The resolved non-terminal row.
     *
     * @throws HttpNotFoundException When the row is absent, terminal, or belongs to another person.
     */
    private function resolveRow(Tree $tree, string $xref, string $key): StoredMatch
    {
        $row = $this->storeForTree($tree)->findOne($xref, $key);

        if (
            !($row instanceof StoredMatch)
            || ($row->personId !== $xref)
            || $row->status->isTerminal()
        ) {
            throw new HttpNotFoundException();
        }

        return $row;
    }

    /**
     * Returns the tree-scoped match store. The seam lets a test subclass inject a store over a temp
     * directory, and lets Task 5 exercise the mid-action terminal race without a real concurrent
     * writer.
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
     * Builds the webtrees-free tree-person projection from the live individual. The display name is
     * stripped of webtrees' HTML markup so the escaping template renders plain text, and the birth
     * place uses the raw GEDCOM place name (not the `<bdi>`-wrapped display form), collapsing an
     * empty place to null.
     *
     * @param Individual $individual The individual under review.
     *
     * @return TreePersonView The pre-formatted tree-person projection.
     */
    private function treePerson(Individual $individual): TreePersonView
    {
        $birthDate  = $individual->getBirthDate();
        $deathDate  = $individual->getDeathDate();
        $birthPlace = $individual->getBirthPlace()->gedcomName();

        return new TreePersonView(
            $individual->xref(),
            strip_tags($individual->fullName()),
            $birthDate->isOK() ? $birthDate->display() : null,
            $birthPlace === '' ? null : $birthPlace,
            $deathDate->isOK() ? $deathDate->display() : null,
            $individual->sex(),
        );
    }

    /**
     * The review-screen page title.
     *
     * @return string The translated title.
     */
    private function reviewTitle(): string
    {
        return I18N::translate('Review match');
    }
}
