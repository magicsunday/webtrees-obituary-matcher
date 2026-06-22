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
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function preg_match;
use function redirect;
use function route;
use function strip_tags;

/**
 * The review-screen route handler. It renders a read-only split-view of one stored match (tree
 * person versus obituary, the explainable per-signal score and conflicts). It is a separate handler
 * so the module stays a thin adapter. Manager access is enforced here because the route is directly
 * callable. A POST carries a reject/uncertain review decision: the row is mutated and the request
 * redirects with a flash, and a row finalised by a concurrent reviewer between resolution and
 * mutation is reported as a warning rather than a 500.
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
     * Handles the review-screen request. GET renders the screen; POST applies the carried review
     * decision (reject or uncertain) and redirects with a flash.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The rendered review screen, or a redirect for a POST decision.
     *
     * @throws HttpNotFoundException     When the key is malformed, or the row is absent or terminal.
     * @throws HttpAccessDeniedException When the user is not a manager of the tree.
     * @throws HttpBadRequestException   When a POST carries an unknown decision action.
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

        // A terminal row already 404'd in resolveRow above, so a POST decision only ever runs against
        // a non-terminal row. The mid-action terminal race (a concurrent reviewer) is caught inside
        // applyDecision(), which is the real correctness guarantee — not this pre-check.
        if ($request->getMethod() === RequestMethodInterface::METHOD_POST) {
            return $this->applyDecision($request, $tree, $xref, $row);
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
     * Applies a non-write-back review decision (reject or uncertain) and redirects with a flash. A
     * row that turned terminal between resolution and mutation is reported as a warning, not a 500.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     * @param Tree                   $tree    The tree the row belongs to.
     * @param string                 $xref    The candidate identifier.
     * @param StoredMatch            $row     The resolved non-terminal row.
     *
     * @return ResponseInterface The redirect response.
     *
     * @throws HttpBadRequestException When the action is neither reject nor uncertain.
     */
    private function applyDecision(ServerRequestInterface $request, Tree $tree, string $xref, StoredMatch $row): ResponseInterface
    {
        $action = Validator::parsedBody($request)->string('action');
        $store  = $this->storeForTree($tree);

        $individualUrl = route(IndividualPage::class, [
            'tree' => $tree->name(),
            'xref' => $xref,
        ]);

        $reviewUrl = route(self::ROUTE_NAME, [
            'tree' => $tree->name(),
            'xref' => $xref,
            'key'  => StoredMatchKey::fromUrl($row->obituaryUrl),
        ]);

        try {
            switch ($action) {
                case 'reject':
                    $store->markRejected($xref, $row->obituaryUrl, null);
                    FlashMessages::addMessage(I18N::translate('The match was rejected.'), 'success');

                    return redirect($individualUrl);

                case 'uncertain':
                    $store->markUncertain($xref, $row->obituaryUrl, null);
                    FlashMessages::addMessage(I18N::translate('The match was marked uncertain.'), 'success');

                    return redirect($reviewUrl);

                default:
                    throw new HttpBadRequestException();
            }
        } catch (TerminalMatchTransitionException) {
            // A concurrent reviewer finalised the row between our resolve and this mutation. The
            // store throw — not the earlier non-terminal check — is the correctness guarantee.
            FlashMessages::addMessage(I18N::translate('This match was already finalised by someone else.'), 'warning');

            return redirect($individualUrl);
        }
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
