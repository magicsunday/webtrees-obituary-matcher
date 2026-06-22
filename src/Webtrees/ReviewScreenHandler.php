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
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Support\ConfirmGate;
use MagicSunday\ObituaryMatcher\Support\MalformedDeathDateException;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function is_array;
use function is_string;
use function preg_match;
use function redirect;
use function route;
use function strip_tags;

/**
 * The review-screen route handler. It renders a read-only split-view of one stored match (tree
 * person versus obituary, the explainable per-signal score and conflicts). It is a separate handler
 * so the module stays a thin adapter. Manager access is enforced here because the route is directly
 * callable. A POST carries a reject/uncertain/confirm review decision: the row is mutated and the
 * request redirects with a flash, and a row finalised by a concurrent reviewer between resolution and
 * mutation is reported as a warning rather than a 500. Confirm additionally writes a sourced DEAT
 * fact to the tree person before transitioning the store (a separate, non-atomic persistence).
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
            return $this->applyDecision($request, $tree, $xref, $individual, $row);
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
     * Applies a carried review decision (reject, uncertain or confirm) and redirects with a flash. A
     * row that turned terminal between resolution and mutation is reported as a warning, not a 500.
     *
     * @param ServerRequestInterface $request    The incoming POST request.
     * @param Tree                   $tree       The tree the row belongs to.
     * @param string                 $xref       The candidate identifier.
     * @param Individual             $individual The tree person under review.
     * @param StoredMatch            $row        The resolved non-terminal row.
     *
     * @return ResponseInterface The redirect response.
     *
     * @throws HttpBadRequestException When the action is none of reject, uncertain or confirm.
     */
    private function applyDecision(
        ServerRequestInterface $request,
        Tree $tree,
        string $xref,
        Individual $individual,
        StoredMatch $row,
    ): ResponseInterface {
        // Default to the empty string so a MISSING action flows to the switch default arm below
        // (a clean HttpBadRequestException) exactly like an unknown action — the handler owns the
        // bad-request semantics uniformly, rather than letting Validator::string throw separately.
        $action = Validator::parsedBody($request)->string('action', '');
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

                case 'confirm':
                    return $this->applyConfirm($tree, $xref, $individual, $row, $individualUrl, $reviewUrl);

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
     * Confirms a match: writes the sourced DEAT fact, then marks the store confirmed. The GEDCOM
     * write and the store write are separate persistences (spec §9) — a write error aborts cleanly
     * with no transition; a store error AFTER a successful write is surfaced (the fact is orphaned).
     *
     * @param Tree        $tree          The tree the row belongs to.
     * @param string      $xref          The candidate identifier.
     * @param Individual  $individual    The tree person.
     * @param StoredMatch $row           The resolved non-terminal row.
     * @param string      $individualUrl The redirect URL to the individual.
     * @param string      $reviewUrl     The redirect URL back to the review screen.
     *
     * @return ResponseInterface The redirect response.
     */
    private function applyConfirm(
        Tree $tree,
        string $xref,
        Individual $individual,
        StoredMatch $row,
        string $individualUrl,
        string $reviewUrl,
    ): ResponseInterface {
        // Re-check the FULL gate server-side before any write — the disabled Confirm button is NOT an
        // authorization control, so a hand-crafted POST must not bypass !hardConflict / exact-date /
        // no-tree-death-date. ConfirmGate is the single source of gate truth (shared with the view
        // model): hardConflict and the exact-date check read the persisted payload (the same source the
        // view model used), treeHasDeathDate is read LIVE. writeDeath's own live re-check below is then
        // defense-in-depth. StoredMatch::fromArray only asserts is_array on the payload (a PHPDoc cast,
        // no runtime key validation), and the on-disk JSON is untrusted (hand-edited / older schema), so
        // the two reads are narrowed defensively here EXACTLY as ReviewViewModel narrows them — keeping
        // the render gate and the write gate reading the payload identically, so a malformed-but-array
        // row degrades to the graceful warning flash instead of an Undefined-array-key 500. The reads go
        // through read(), which erases the static ClassifiedMatchArray shape to mixed (as the view model
        // does) so the per-field narrowing is real defence, not PHPDoc-certain dead code.
        $factsRaw     = $this->read($row->match, 'extractedFacts');
        $facts        = is_array($factsRaw) ? $factsRaw : [];
        $isoRaw       = $facts['deathDate'] ?? null;
        $iso          = is_string($isoRaw) ? $isoRaw : null;
        $hardConflict = $this->read($row->match, 'hardConflict') === true;

        if (!ConfirmGate::evaluate($hardConflict, $individual->getDeathDate()->isOK(), $iso)->canConfirm) {
            FlashMessages::addMessage(I18N::translate('This match can no longer be confirmed.'), 'warning');

            return redirect($reviewUrl);
        }

        // Block A — the GEDCOM write. Any precondition/date failure aborts with no store transition, so
        // the tree and the store both stay in their pre-confirm state.
        try {
            // $iso is guaranteed an exact ISO date by the gate above, so it is a string here.
            $writeBack = $this->obituaryWriteBack()->writeDeath($individual, (string) $iso, $row->obituaryUrl);
        } catch (DeathDateAlreadyPresentException) {
            FlashMessages::addMessage(I18N::translate('This individual already has a death date; nothing was written.'), 'warning');

            return redirect($reviewUrl);
        } catch (WriteBackPreconditionException|MalformedDeathDateException) {
            FlashMessages::addMessage(I18N::translate('The obituary did not carry a writable death date.'), 'warning');

            return redirect($reviewUrl);
        }

        // Block B — the store transition AFTER a successful write. This is the orphan-risk path: the
        // DEAT is already in the tree, so a transition failure here leaves the fact written but
        // unrecorded. The two persistences are deliberately non-atomic (spec §9); a failure is surfaced
        // (error flash + log), never reported as success.
        try {
            $transitioned = $this->storeForTree($tree)->markConfirmed($xref, $row->obituaryUrl, $writeBack);
        } catch (TerminalMatchTransitionException) {
            FlashMessages::addMessage(I18N::translate('This match was already finalised by someone else.'), 'warning');

            return redirect($individualUrl);
        } catch (Throwable $throwable) {
            // The DEAT is already in the tree but the store did not record the confirm — log the orphan
            // for an administrator to reconcile (webtrees' DB error log, not stdout), then surface it.
            Log::addErrorLog('Obituary matcher: confirm wrote the DEAT but the store transition failed: ' . $throwable->getMessage());
            FlashMessages::addMessage(I18N::translate('The death fact was written but could not be recorded; please review it.'), 'danger');

            return redirect($individualUrl);
        }

        if (!$transitioned) {
            FlashMessages::addMessage(I18N::translate('This match was already finalised by someone else.'), 'warning');

            return redirect($individualUrl);
        }

        FlashMessages::addMessage(I18N::translate('The match was confirmed and the death date was written.'), 'success');

        return redirect($individualUrl);
    }

    /**
     * Builds the GEDCOM write-back writer. The seam lets a test inject a throwing or spy writer.
     *
     * @return ObituaryWriteBack The write-back writer.
     */
    protected function obituaryWriteBack(): ObituaryWriteBack
    {
        return new ObituaryWriteBack();
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
     * Builds the webtrees-free tree-person projection from the live individual. The display name and
     * both dates are stripped of webtrees' HTML markup so the escaping template renders plain text
     * (`Date::display()` returns a `<span class="date">…</span>`, which would otherwise leak as
     * escaped markup), and the birth place uses the raw GEDCOM place name (not the `<bdi>`-wrapped
     * display form), collapsing an empty place to null.
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
            $birthDate->isOK() ? strip_tags($birthDate->display()) : null,
            $birthPlace === '' ? null : $birthPlace,
            $deathDate->isOK() ? strip_tags($deathDate->display()) : null,
        );
    }

    /**
     * Reads a key from the persisted match payload as mixed, erasing the static ClassifiedMatchArray
     * shape so the confirm gate's per-field defensive narrowing is treated as live code rather than
     * PHPDoc-certain dead code. The payload was reconstructed from untrusted on-disk JSON
     * ({@see StoredMatch::fromArray()} only asserts it is an array), so it may be malformed-but-array;
     * this mirrors {@see ReviewViewModel}'s own read() so the render gate and the write gate narrow the
     * payload identically and cannot drift.
     *
     * @param array<array-key, mixed> $payload The persisted match payload.
     * @param string                  $key     The key to read.
     *
     * @return mixed The raw value, or null when the key is absent.
     */
    private function read(array $payload, string $key): mixed
    {
        return $payload[$key] ?? null;
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
