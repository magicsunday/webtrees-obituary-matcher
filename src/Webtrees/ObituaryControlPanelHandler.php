<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use DomainException;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Domain\BandThreshold;
use MagicSunday\ObituaryMatcher\Domain\ScoreWeights;
use MagicSunday\ObituaryMatcher\Queue\CapabilitiesProbeResult;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilities;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilitiesProbe;
use MagicSunday\ObituaryMatcher\Queue\ProbeStatus;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\AdditionalFindersEditor;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Ui\AdditionalFinderRowView;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelPresenter;
use MagicSunday\ObituaryMatcher\Ui\FinderConnectionView;
use MagicSunday\ObituaryMatcher\Ui\ProbeReadoutView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function ctype_digit;
use function is_array;
use function is_string;
use function redirect;
use function route;
use function strip_tags;
use function strlen;

/**
 * The admin-only control-panel route handler. A GET renders the slim panel — the persisted age/limit
 * settings, the finder connection, the trees offered for a finder trigger and the number of open finder
 * jobs. A POST carries one action: `save` persists the settings STRICTLY (both validate in range or
 * NEITHER is written, never coercing a malformed value to a default), `save-finder` persists the finder
 * connection STRICTLY, and `trigger` enqueues one bounded finder job for a single tree using the
 * PERSISTED settings — these three PRG-redirect with a flash. The `test` action is the deliberate
 * exception: it runs a read-only capabilities probe against the SUBMITTED finder connection and
 * RE-RENDERS the panel with a transient readout (no redirect, no flash, no persistence). Admin access
 * is enforced here because the route is directly callable.
 *
 * The settings are READ leniently (a corrupt/out-of-range stored preference falls back to the default,
 * never fatal) but WRITTEN strictly. The handler holds the only {@see RestPendingLedger}/
 * {@see EnqueueService} references; the {@see ControlPanelPresenter} stays webtrees-free and Queue-free.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class ObituaryControlPanelHandler implements RequestHandlerInterface
{
    use ViewResponseTrait;

    /**
     * The route name used to register and to build links to this handler.
     */
    public const string ROUTE_NAME = 'obituary-matcher-control-panel';

    /**
     * The route URL pattern.
     */
    public const string ROUTE_URL = '/admin/obituary-matcher';

    /**
     * The default minimum-age setting (years) applied when none is persisted or the stored value is
     * unusable.
     */
    private const int DEFAULT_MIN_AGE = CandidateCriteria::DEFAULT_MIN_AGE;

    /**
     * The default per-run candidate limit applied when none is persisted or the stored value is unusable.
     */
    private const int DEFAULT_LIMIT = 50;

    /**
     * The inclusive lower bound for the minimum-age setting (the shared candidate-age domain floor).
     */
    private const int MIN_AGE_FLOOR = CandidateCriteria::AGE_FLOOR;

    /**
     * The inclusive upper bound for the minimum-age setting (the shared candidate-age domain ceiling).
     */
    private const int MIN_AGE_CEILING = CandidateCriteria::AGE_CEILING;

    /**
     * The inclusive lower bound for the per-run candidate limit.
     */
    private const int LIMIT_FLOOR = 1;

    /**
     * The inclusive upper bound for the per-run candidate limit.
     */
    private const int LIMIT_CEILING = 500;

    /**
     * The seconds to wait for the TCP connection to the finder before the capabilities probe treats it
     * as unreachable. Mirrors {@see JobTransportFactory}'s bound so the admin probe and the live
     * transport share the same reachability budget.
     */
    private const int PROBE_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * The seconds to wait for a finder response before the capabilities probe treats the request as a
     * transient fault. Mirrors {@see JobTransportFactory}'s request bound.
     */
    private const int PROBE_REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Constructor.
     *
     * @param ObituaryMatcherModule $module The module instance, for its setPreference/getPreference and
     *                                      its registered view namespace.
     */
    public function __construct(
        private readonly ObituaryMatcherModule $module,
    ) {
        // An admin-global page (not a tree page), so render inside the administration layout.
        $this->layout = 'layouts/administration';
    }

    /**
     * Handles the control-panel request. A GET renders the panel; a POST applies the carried action
     * (save settings or trigger a finder run) and PRG-redirects with a flash.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The rendered panel, or a redirect for a POST action.
     *
     * @throws HttpAccessDeniedException When the user is not an administrator.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Validator::attributes($request)->user();

        if (!Auth::isAdmin($user)) {
            throw new HttpAccessDeniedException();
        }

        if ($request->getMethod() === RequestMethodInterface::METHOD_POST) {
            return $this->handlePost($request);
        }

        return $this->renderPanel();
    }

    /**
     * Dispatches a POST action. The `save`/`save-finder`/`trigger` actions all PRG-redirect; the `test`
     * action is the deliberate exception — it runs a read-only reachability probe and RE-RENDERS the
     * panel with a transient readout (no redirect). An unknown/missing action simply PRG-redirects back
     * to the panel.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response, or the re-rendered panel for the `test` action.
     */
    private function handlePost(ServerRequestInterface $request): ResponseInterface
    {
        $action = Validator::parsedBody($request)->string('action', '');

        return match ($action) {
            'save'          => $this->saveSettings($request),
            'save-finder'   => $this->saveFinder($request),
            'save-weights'  => $this->saveWeights($request),
            'reset-weights' => $this->resetWeights(),
            'test'          => $this->testConnection($request),
            'trigger'       => $this->triggerFinder($request),
            default         => redirect(route(self::ROUTE_NAME)),
        };
    }

    /**
     * Strictly persists the settings: both the min_age and the limit must parse to a clean integer in
     * range, or NEITHER is written (no partial save, no coercion to a default). Always PRG-redirects.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function saveSettings(ServerRequestInterface $request): ResponseInterface
    {
        $minAgeRaw = Validator::parsedBody($request)->string('min_age', '');
        $limitRaw  = Validator::parsedBody($request)->string('limit', '');

        $minAge = $this->parseStrictInt($minAgeRaw, self::MIN_AGE_FLOOR, self::MIN_AGE_CEILING);
        $limit  = $this->parseStrictInt($limitRaw, self::LIMIT_FLOOR, self::LIMIT_CEILING);

        if (
            ($minAge !== null)
            && ($limit !== null)
        ) {
            $this->module->setPreference('min_age', (string) $minAge);
            $this->module->setPreference('limit', (string) $limit);
            FlashMessages::addMessage(I18N::translate('Settings saved.'), 'success');
        } else {
            FlashMessages::addMessage(I18N::translate('Settings could not be saved.'), 'danger');
        }

        return redirect(route(self::ROUTE_NAME));
    }

    /**
     * Strictly persists the six editable scoring caps: EVERY field must parse to a clean integer within
     * its bounds, or NOTHING is written (no partial save, no coercion) — matching the min_age/limit
     * both-or-neither discipline. The bounds and preference keys come from {@see ScoreWeights::FIELDS},
     * the single source shared with the reader. Always PRG-redirects.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function saveWeights(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, int> $parsed */
        $parsed = [];

        $body = Validator::parsedBody($request);

        foreach (ScoreWeights::FIELDS as $meta) {
            $value = $this->parseStrictInt(
                $body->string($meta['key'], ''),
                $meta['min'],
                $meta['max'],
            );

            if ($value === null) {
                FlashMessages::addMessage(I18N::translate('Settings could not be saved.'), 'danger');

                return redirect(route(self::ROUTE_NAME));
            }

            $parsed[$meta['key']] = $value;
        }

        foreach ($parsed as $key => $value) {
            $this->module->setPreference($key, (string) $value);
        }

        FlashMessages::addMessage(I18N::translate('Settings saved.'), 'success');

        return redirect(route(self::ROUTE_NAME));
    }

    /**
     * Restores the six editable scoring caps to their enriched-profile defaults by writing each default
     * back through {@see ScoreWeights::FIELDS}. Always PRG-redirects.
     *
     * @return ResponseInterface The redirect response.
     */
    private function resetWeights(): ResponseInterface
    {
        foreach (ScoreWeights::FIELDS as $meta) {
            $this->module->setPreference($meta['key'], (string) $meta['default']);
        }

        FlashMessages::addMessage(I18N::translate('The scoring weights were reset to their defaults.'), 'success');

        return redirect(route(self::ROUTE_NAME));
    }

    /**
     * Strictly persists the one REST finder connection. The base URL and (when present) the token are
     * validated at the single {@see FinderConnection::rest()} source FIRST: on a validation failure
     * NOTHING is persisted (both-or-neither) and a danger flash is shown; on success the base URL is
     * written, and the token is set only when one was supplied (a blank field keeps the existing token)
     * or explicitly cleared by the remove flag. The token VALUE is never logged, flashed or echoed.
     *
     * Activation is ATOMIC: the `finder_transport === 'rest'` consent marker that activates the
     * connection in {@see self::finderConnection()} is first DEACTIVATED (set to a non-`'rest'` value),
     * then the credentials are written, and the marker is REACTIVATED to `'rest'` only AFTER every
     * credential write has succeeded. Each setPreference is an independently committed write, so without
     * the leading deactivation an already-active (`'rest'`) install being re-pointed would keep REST live
     * throughout the update — a base-URL write that landed before a failing token write would resolve the
     * NEW URL with the STALE token. The deactivate-first/reactivate-last sequence guarantees a partial
     * save NEVER leaves REST active against a half-written credential set, for an already-active install
     * just as for a dormant legacy `'file'` one. Always PRG-redirects.
     *
     * The submitted ADDITIONAL finders (§5.2f increment 2) are validated and normalised by
     * {@see AdditionalFindersEditor::toJson()} inside the SAME all-or-nothing gate: an invalid or
     * duplicate additional row rejects the whole save (primary included), and the resulting
     * `finder_additional` JSON is persisted during the deactivated window alongside the primary
     * credentials, so the whole connection activates atomically.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function saveFinder(ServerRequestInterface $request): ResponseInterface
    {
        // A blank token field means "keep the existing token" at persist time, so it resolves to null
        // here (nothing new to validate); the existing token stays untouched below.
        [$baseUrl, $tokenRaw, $remove, $token] = $this->resolveFinderInput($request, null);

        $submittedAdditional = $this->submittedAdditionalFinders($request);

        try {
            // Validate the base URL and the resolved token's control characters in one go; the returned
            // object is not needed because persistence stores the flat preferences.
            FinderConnection::rest($baseUrl, $token);

            // §5.2f: validate and normalise the additional finders in the SAME all-or-nothing gate. The
            // primary's base-URL identity is reserved so an additional finder can never duplicate it, and
            // the currently-stored list feeds the keep-by-identity token resolution (a blank additional
            // token keeps the finder's stored secret). The first invalid/duplicate row throws, rejecting
            // the whole save before anything is persisted — the same both-or-neither contract the primary
            // uses. The returned JSON is persisted below.
            $additionalJson = AdditionalFindersEditor::toJson(
                $submittedAdditional,
                $this->module->getPreference('finder_additional', ''),
                [FinderConnection::baseUrlKeyFor($baseUrl)],
            );
        } catch (InvalidArgumentException) {
            FlashMessages::addMessage(I18N::translate('Finder connection could not be saved.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        // DEACTIVATE the consent marker FIRST. The REST-only UI no longer offers a transport toggle, so
        // `finder_transport` is purely the internal "REST explicitly configured" marker; clearing it
        // before any credential write means that — because each setPreference is an independently
        // committed write — a failure persisting the base URL or token (or another request interleaving)
        // from here on leaves REST INACTIVE, for an already-active 'rest' install just as for a dormant
        // legacy 'file' one. Without this leading deactivation an already-active install keeps the 'rest'
        // marker throughout the update, so a base-URL write that committed before a failing token write
        // would resolve the NEW URL against the STALE token.
        $this->module->setPreference('finder_transport', '');

        $this->module->setPreference('finder_base_url', $baseUrl);

        if ($remove) {
            $this->module->setPreference('finder_token', '');
        } elseif ($tokenRaw !== '') {
            $this->module->setPreference('finder_token', $tokenRaw);
        }

        // Persist the additional finders while REST is still deactivated: the resolver reads
        // `finder_additional` only under the `finder_transport === 'rest'` gate, so writing it before the
        // reactivation below keeps the whole connection (primary + additional) inert until the save
        // completes — an empty string clears the list when none were submitted.
        $this->module->setPreference('finder_additional', $additionalJson);

        // REACTIVATE the consent marker LAST, only after every connection field has been committed. This
        // lifts the migration gate in self::finderConnection() atomically — a legacy file-mode install
        // with retained REST creds stays correctly dormant until a full save completes.
        $this->module->setPreference('finder_transport', 'rest');

        FlashMessages::addMessage(I18N::translate('Finder connection saved.'), 'success');

        return redirect(route(self::ROUTE_NAME));
    }

    /**
     * Reads the submitted REST finder input shared by the `save-finder` and `test` POST actions: the
     * base URL, the raw submitted token field, the remove flag and the token resolved under the
     * REMOVE-wins precedence (a remove discards the submitted token so its content is never validated,
     * else a non-empty submitted token wins, else the $blankFallback is used). `save-finder` passes null
     * as the blank fallback (a blank field keeps the existing token); `test` passes the persisted token
     * so a blank re-test reuses it. Validating the remove-resolved token (not the raw field) keeps the
     * REMOVE-wins invariant: a remove must succeed even when the discarded field carried a control
     * character.
     *
     * @param ServerRequestInterface $request       The incoming POST request.
     * @param string|null            $blankFallback The token to use when the field is blank and remove is unset.
     *
     * @return array{0: string, 1: string, 2: bool, 3: string|null} The baseUrl, raw token field, remove flag and resolved token.
     */
    private function resolveFinderInput(ServerRequestInterface $request, ?string $blankFallback): array
    {
        $baseUrl  = Validator::parsedBody($request)->string('base_url', '');
        $tokenRaw = Validator::parsedBody($request)->string('token', '');
        $remove   = Validator::parsedBody($request)->boolean('remove_token', false);

        if ($remove) {
            $token = null;
        } elseif ($tokenRaw !== '') {
            $token = $tokenRaw;
        } else {
            $token = $blankFallback;
        }

        return [$baseUrl, $tokenRaw, $remove, $token];
    }

    /**
     * Reads the submitted additional-finder rows (§5.2f increment 2) from the parsed body into the flat
     * shape {@see AdditionalFindersEditor::toJson()} consumes. The rows arrive as a nested `additional`
     * array (one entry per list row, each with `base_url`, `token` and the checkbox flags `active` /
     * `remove_token`). The body is narrowed defensively — a missing `additional`, a non-array row, or a
     * non-string field never throws — so an entirely absent list (the single-finder case) yields an empty
     * list and a malformed field degrades to its empty default rather than a TypeError. The checkbox flags
     * are read by PRESENCE (an unchecked checkbox is simply not submitted), matching the plain
     * value-carrying checkboxes the template renders.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return list<array{baseUrl: string, token: string, active: bool, removeToken: bool}> The submitted rows.
     */
    private function submittedAdditionalFinders(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return [];
        }

        $rawRows = $parsedBody['additional'] ?? null;

        if (!is_array($rawRows)) {
            return [];
        }

        $rows = [];

        foreach ($rawRows as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }

            $baseUrl = $rawRow['base_url'] ?? null;
            $token   = $rawRow['token'] ?? null;

            $rows[] = [
                'baseUrl'     => is_string($baseUrl) ? $baseUrl : '',
                'token'       => is_string($token) ? $token : '',
                'active'      => isset($rawRow['active']),
                'removeToken' => isset($rawRow['remove_token']),
            ];
        }

        return $rows;
    }

    /**
     * Runs a read-only capabilities probe against the SUBMITTED (not necessarily persisted) finder
     * connection and RE-RENDERS the panel with a transient readout — the deliberate exception to the
     * POST-redirect-GET contract, because a reachability test produces a result to show, not a state
     * change to redirect past. The token precedence matches {@see self::saveFinder()} (REMOVE wins): an
     * explicit remove flag forces an unauthenticated probe, else a non-empty submitted token wins, else the
     * persisted token is reused — so the admin can test a typed-but-unsaved token, or the stored one,
     * without re-entering it, and ticking remove probes exactly what a save would persist. A missing base
     * URL or a base URL/token the {@see FinderConnection::rest()} source rejects yields an invalid readout
     * WITHOUT probing. The probe never throws, but the seam wiring is still guarded so no probe fault
     * escapes as a 500. The token VALUE is never logged, flashed or echoed — only the `tokenIsSet` boolean
     * (from the PERSISTED preference) reaches the view.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The re-rendered panel carrying the probe readout.
     */
    private function testConnection(ServerRequestInterface $request): ResponseInterface
    {
        // A blank token field falls back to the persisted token so the admin can re-test the stored
        // connection without re-entering the secret.
        $persisted = $this->module->getPreference('finder_token', '');

        [$baseUrl, , , $token] = $this->resolveFinderInput($request, $persisted === '' ? null : $persisted);

        try {
            $connection = FinderConnection::rest($baseUrl, $token);
        } catch (InvalidArgumentException) {
            // A missing/malformed base URL or a control-character token is rejected at the single source
            // before any HTTP is attempted, so the readout reports the invalid configuration without probing.
            return $this->renderTestResult($baseUrl, CapabilitiesProbeResult::invalid());
        }

        try {
            $result = $this->capabilitiesProbe($connection)->probe();
        } catch (Throwable) {
            // The probe is contractually non-throwing, but constructing the seam's HTTP stack must never
            // 500 the panel: a wiring fault degrades to an unreachable readout like any transport fault.
            $result = CapabilitiesProbeResult::unreachable();
        }

        return $this->renderTestResult($baseUrl, $result);
    }

    /**
     * Builds the finder view echoing the SUBMITTED base URL plus the persisted token-is-set flag, maps
     * the probe result to its plain readout and re-renders the panel.
     *
     * @param string                  $baseUrl The submitted base URL, echoed back into the form.
     * @param CapabilitiesProbeResult $result  The probe outcome to project into the readout.
     *
     * @return ResponseInterface The re-rendered panel.
     */
    private function renderTestResult(string $baseUrl, CapabilitiesProbeResult $result): ResponseInterface
    {
        return $this->renderPanelWith(new FinderConnectionView(
            $baseUrl,
            $this->module->getPreference('finder_token', '') !== '',
            $this->probeReadout($result),
            $this->additionalFinderViews(),
        ));
    }

    /**
     * Maps a probe result onto the plain {@see ProbeReadoutView} the template consumes: the status enum
     * becomes a string key and the narrowed capabilities are copied into plain arrays (each portal a
     * plain record with empty-string/empty-list defaults for its optional fields). The mapper emits
     * PLAIN strings — escaping every sink is the template's job.
     *
     * @param CapabilitiesProbeResult $result The probe outcome.
     *
     * @return ProbeReadoutView The plain readout view model.
     */
    private function probeReadout(CapabilitiesProbeResult $result): ProbeReadoutView
    {
        $statusKey = match ($result->status) {
            ProbeStatus::Reachable   => 'reachable',
            ProbeStatus::Unreachable => 'unreachable',
            ProbeStatus::Invalid     => 'invalid',
        };

        $capabilities = $result->capabilities;

        if (!$capabilities instanceof FinderCapabilities) {
            return new ProbeReadoutView($statusKey, $result->httpStatus, null, null, [], [], [], []);
        }

        $portals = [];

        foreach ($capabilities->portals as $portal) {
            $portals[] = [
                'id'      => $portal->id,
                'name'    => $portal->name ?? '',
                'country' => $portal->country ?? '',
                'regions' => $portal->regions,
            ];
        }

        return new ProbeReadoutView(
            $statusKey,
            $result->httpStatus,
            $capabilities->finderId,
            $capabilities->finderVersion,
            $capabilities->schemaVersions,
            $portals,
            $capabilities->noticeFields,
            $capabilities->features,
        );
    }

    /**
     * Triggers a per-tree finder run with the PERSISTED settings: an unknown tree id flashes and
     * redirects; a finder connection that is not configured (no/invalid stored base URL) flashes and
     * redirects; an unresolvable storage location flashes and redirects; otherwise the real
     * EnqueueService is wired over the REST connection and the resolved ledger root and one bounded job
     * is enqueued. A null jobId (no candidate matched) flashes a distinct warning; an enqueue failure
     * flashes a fixed error. Always PRG-redirects.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function triggerFinder(ServerRequestInterface $request): ResponseInterface
    {
        $treeId = Validator::parsedBody($request)->integer('tree', 0);

        try {
            $tree = $this->treeService()->find($treeId);
        } catch (DomainException) {
            FlashMessages::addMessage(I18N::translate('Unknown tree.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        $connection = $this->finderConnection();

        if (!$connection instanceof FinderConnection) {
            FlashMessages::addMessage(I18N::translate('Configure the finder connection first.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        $restPendingRoot = $this->restPendingRoot();

        if ($restPendingRoot === null) {
            FlashMessages::addMessage(I18N::translate('The finder storage location is unavailable.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        try {
            $summary = $this->enqueueService($connection, $restPendingRoot)->enqueue(
                $tree->id(),
                $this->readLimit(),
                $this->readMinAge(),
                I18N::languageTag(),
            );
        } catch (DomainException) {
            // EnqueueService re-resolves the tree on its own TreeService, so a tree that vanished
            // between the resolve above and this enqueue (a TOCTOU race) throws DomainException here —
            // NOT a RuntimeException — and must flash + redirect, mirroring tools/enqueue.php's separate
            // DomainException arm rather than escaping handle().
            FlashMessages::addMessage(I18N::translate('Unknown tree.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        } catch (Throwable) {
            // The final arm catches ANY Throwable (DomainException is handled above for the distinct
            // 'Unknown tree.' message) so no unexpected enqueue failure escapes handle() as a 500: the
            // handler's contract is that the trigger path always flashes + PRG-redirects.
            FlashMessages::addMessage(I18N::translate('Search job could not be queued.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        if ($summary->jobId !== null) {
            FlashMessages::addMessage(I18N::translate('Search job queued (%s).', $summary->jobId), 'success');
        } else {
            FlashMessages::addMessage(
                I18N::translate('No candidates matched the current settings; nothing was queued.'),
                'warning',
            );
        }

        return redirect(route(self::ROUTE_NAME));
    }

    /**
     * Renders the read-only panel for a plain GET: the persisted-finder view carries no probe (a
     * reachability test is the later `test` POST action). Delegates the shared tree/settings/jobs
     * assembly to {@see self::renderPanelWith()}.
     *
     * @return ResponseInterface The rendered panel response.
     */
    private function renderPanel(): ResponseInterface
    {
        return $this->renderPanelWith($this->finderConnectionView());
    }

    /**
     * Renders the panel around the given finder view: the prefilled settings, the trees offered for a
     * trigger and the number of open finder jobs. Both the GET render and the `test` re-render share this
     * assembly, differing only in the finder view they pass (a probe-less GET view vs a test view
     * carrying the readout). Protected so a test can capture the finder view it receives.
     *
     * @param FinderConnectionView $finder The finder view (with or without a probe readout) to render.
     *
     * @return ResponseInterface The rendered panel response.
     */
    protected function renderPanelWith(FinderConnectionView $finder): ResponseInterface
    {
        $trees = [];

        foreach ($this->treeService()->all() as $tree) {
            $trees[] = [
                'id'   => $tree->id(),
                'name' => strip_tags($tree->title()),
            ];
        }

        $view = (new ControlPanelPresenter())->build(
            $this->readMinAge(),
            $this->readLimit(),
            $trees,
            $this->openJobCount(),
            $finder,
            $this->module->scoreWeights(),
            BandThreshold::all(),
        );

        return $this->viewResponse($this->module->name() . '::control-panel', [
            'title' => I18N::translate('Death notices'),
            'view'  => $view,
        ]);
    }

    /**
     * The number of open (submitted-but-not-yet-drained) finder jobs for the panel readout. Resolved
     * through the REST pending ledger over the seamed ledger root; a null/missing root (no install
     * located, or the ledger never written) yields 0 WITHOUT creating it (a render is read-only).
     *
     * @return int The number of open finder jobs.
     */
    protected function openJobCount(): int
    {
        $root = $this->restPendingRoot();

        if ($root === null) {
            return 0;
        }

        return (new RestPendingLedger($root))->openJobCount();
    }

    /**
     * Builds the finder-connection view model from the persisted preferences. A GET render carries no
     * probe (a reachability test is a later POST action). The token VALUE never reaches the view — only
     * a boolean recording whether one is configured.
     *
     * @return FinderConnectionView The finder-connection view model.
     */
    private function finderConnectionView(): FinderConnectionView
    {
        return new FinderConnectionView(
            $this->module->getPreference('finder_base_url', ''),
            $this->module->getPreference('finder_token', '') !== '',
            null,
            $this->additionalFinderViews(),
        );
    }

    /**
     * Projects the persisted additional finders (§5.2f increment 2) into the view models the panel renders
     * — EVERY stored finder (active and inactive), so the admin can edit or re-activate each. The token
     * VALUE never reaches the view: {@see AdditionalFindersEditor::storedRows()} exposes only a
     * `tokenIsSet` boolean, mapped one-to-one onto {@see AdditionalFinderRowView} here.
     *
     * @return list<AdditionalFinderRowView> The additional-finder view models.
     */
    private function additionalFinderViews(): array
    {
        $views = [];

        foreach (AdditionalFindersEditor::storedRows($this->module->getPreference('finder_additional', '')) as $row) {
            $views[] = new AdditionalFinderRowView($row['baseUrl'], $row['tokenIsSet'], $row['active']);
        }

        return $views;
    }

    /**
     * The persisted minimum-age setting, read leniently: an unusable stored value (non-integer or out
     * of range) falls back to the default rather than throwing.
     *
     * @return int The minimum-age setting.
     */
    private function readMinAge(): int
    {
        return $this->parseStrictInt(
            $this->module->getPreference('min_age', (string) self::DEFAULT_MIN_AGE),
            self::MIN_AGE_FLOOR,
            self::MIN_AGE_CEILING,
        ) ?? self::DEFAULT_MIN_AGE;
    }

    /**
     * The persisted per-run candidate limit, read leniently: an unusable stored value (non-integer or
     * out of range) falls back to the default rather than throwing.
     *
     * @return int The per-run candidate limit.
     */
    private function readLimit(): int
    {
        return $this->parseStrictInt(
            $this->module->getPreference('limit', (string) self::DEFAULT_LIMIT),
            self::LIMIT_FLOOR,
            self::LIMIT_CEILING,
        ) ?? self::DEFAULT_LIMIT;
    }

    /**
     * Parses a raw string into an integer only when it is a clean, unsigned-digit integer within the
     * inclusive [$min, $max] bounds; otherwise returns null. Never coerces a malformed value (e.g.
     * "90abc", "", "12.5", " 90") to a number — the bounds here are non-negative, so a plain
     * {@see ctype_digit()} digit check suffices.
     *
     * @param string $value The raw input string.
     * @param int    $min   The inclusive lower bound.
     * @param int    $max   The inclusive upper bound.
     *
     * @return int|null The parsed integer, or null when the string is not a clean in-range integer.
     */
    private function parseStrictInt(string $value, int $min, int $max): ?int
    {
        // The length cap (well above any 3-digit bound, generous enough for a leading-zero like '0500')
        // is a defensive guard against a pathologically long digit string before the bounds check does
        // the real work: a huge digit run saturates the (int) cast to PHP_INT_MAX (which the bounds then
        // reject anyway), so this only short-circuits the pathological case.
        if (!ctype_digit($value) || (strlen($value) > 9)) {
            return null;
        }

        $parsed = (int) $value;

        if (
            ($parsed < $min)
            || ($parsed > $max)
        ) {
            return null;
        }

        return $parsed;
    }

    /**
     * The webtrees tree lookup. A seam so a test can pin it.
     *
     * @return TreeService The tree service.
     */
    protected function treeService(): TreeService
    {
        return new TreeService(new GedcomImportService());
    }

    /**
     * Builds the real enqueue producer over the given REST connection and ledger root, mirroring the
     * `tools/enqueue.php` wiring exactly. A seam so a test can inject a producer that throws (to pin the
     * trigger path's always-PRG-redirect contract on a DomainException/RuntimeException from the
     * producer).
     *
     * @param FinderConnection $connection      The REST finder connection.
     * @param string           $restPendingRoot The REST in-flight ledger root.
     *
     * @return EnqueueService The wired enqueue producer.
     */
    protected function enqueueService(FinderConnection $connection, string $restPendingRoot): EnqueueService
    {
        return EnqueueServiceFactory::create($connection, $restPendingRoot);
    }

    /**
     * Reads the finder connection from module preferences, or null when it is not configured. The REST
     * endpoint activates ONLY on explicit admin consent, recorded as `finder_transport === 'rest'` by a
     * successful {@see self::saveFinder()}: any other stored value (the legacy `'file'` from a pre-cutover
     * install, or the unset default) is treated as not configured (null) EVEN when a base URL lingers — so
     * REST creds retained from a prior configuration are never silently reactivated and no person data is
     * transmitted to an endpoint the admin had disabled. An empty stored base URL is likewise "not
     * configured" (null); a stored-but-invalid base URL the {@see FinderConnection::rest()} source rejects
     * is treated as not configured (null) rather than escaping as an exception. The token VALUE never
     * leaves this method except into the connection.
     *
     * @return FinderConnection|null The configured REST connection, or null when not configured.
     */
    protected function finderConnection(): ?FinderConnection
    {
        return FinderConnectionResolver::fromConfig(
            $this->module->getPreference('finder_transport', ''),
            $this->module->getPreference('finder_base_url', ''),
            $this->module->getPreference('finder_token', ''),
        );
    }

    /**
     * Builds the capabilities probe over a bounded Guzzle client, mirroring {@see JobTransportFactory}'s
     * REST wiring so the admin probe and the live transport share the same connect/request budget. A
     * protected seam so a test can drive the probe over a scripted PSR-18 double (the
     * {@see FinderCapabilitiesProbe} is a final readonly class and cannot be stubbed directly).
     *
     * @param FinderConnection $connection The REST connection to probe.
     *
     * @return FinderCapabilitiesProbe The wired capabilities probe.
     */
    protected function capabilitiesProbe(FinderConnection $connection): FinderCapabilitiesProbe
    {
        return new FinderCapabilitiesProbe(
            new Client([
                'connect_timeout' => self::PROBE_CONNECT_TIMEOUT_SECONDS,
                'timeout'         => self::PROBE_REQUEST_TIMEOUT_SECONDS,
                // Pin the no-redirect SSRF invariant at the client level so a future move off the
                // PSR-18 sendRequest() path (which hard-codes allow_redirects=false per request)
                // cannot silently follow a 3xx into an attacker-chosen internal host.
                'allow_redirects' => false,
            ]),
            new HttpFactory(),
            $connection,
        );
    }

    /**
     * Resolves the running instance's default REST in-flight ledger root, or null when it cannot be
     * located. A protected seam so a test can redirect it onto a throwaway directory.
     *
     * @return string|null The REST-pending ledger root, or null when unresolvable.
     */
    protected function restPendingRoot(): ?string
    {
        return (new WebtreesInstallLocator(dirname(__DIR__, 2)))->defaultRestPendingRoot();
    }
}
