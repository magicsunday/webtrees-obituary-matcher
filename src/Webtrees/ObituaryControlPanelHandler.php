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
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use MagicSunday\ObituaryMatcher\Ui\ControlPanelPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function ctype_digit;
use function redirect;
use function route;
use function strip_tags;

/**
 * The admin-only control-panel route handler. A GET renders the slim panel — the persisted age/limit
 * settings, the trees offered for a feeder trigger and the recent queue-job status. A POST carries one
 * of two actions: `save` persists the settings STRICTLY (both validate in range or NEITHER is written,
 * never coercing a malformed value to a default), and `trigger` enqueues one bounded feeder job for a
 * single tree using the PERSISTED settings. Both POST actions PRG-redirect with a flash. Admin access
 * is enforced here because the route is directly callable.
 *
 * The settings are READ leniently (a corrupt/out-of-range stored preference falls back to the default,
 * never fatal) but WRITTEN strictly. The handler holds the only {@see \MagicSunday\ObituaryMatcher\Queue\JobStatus}/
 * {@see QueueClient}/{@see EnqueueService} references; the {@see ControlPanelPresenter} stays
 * webtrees-free and Queue-free.
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
    private const int DEFAULT_MIN_AGE = 90;

    /**
     * The default per-run candidate limit applied when none is persisted or the stored value is unusable.
     */
    private const int DEFAULT_LIMIT = 50;

    /**
     * The inclusive bounds for the minimum-age setting.
     */
    private const int MIN_AGE_FLOOR = 0;

    /**
     * The inclusive upper bound for the minimum-age setting.
     */
    private const int MIN_AGE_CEILING = 120;

    /**
     * The inclusive lower bound for the per-run candidate limit.
     */
    private const int LIMIT_FLOOR = 1;

    /**
     * The inclusive upper bound for the per-run candidate limit.
     */
    private const int LIMIT_CEILING = 500;

    /**
     * The number of recent jobs surfaced in the panel's status list.
     */
    private const int RECENT_JOBS = 20;

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
     * (save settings or trigger a feeder run) and PRG-redirects with a flash.
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
     * Dispatches a POST action. An unknown/missing action simply PRG-redirects back to the panel.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function handlePost(ServerRequestInterface $request): ResponseInterface
    {
        $action = Validator::parsedBody($request)->string('action', '');

        return match ($action) {
            'save'    => $this->saveSettings($request),
            'trigger' => $this->triggerFeeder($request),
            default   => redirect(route(self::ROUTE_NAME)),
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
     * Triggers a per-tree feeder run with the PERSISTED settings: an unknown tree id flashes and
     * redirects; otherwise the real EnqueueService is wired over the resolved queue root and one bounded
     * job is enqueued. A null jobId (no candidate matched) flashes a distinct warning; a queue failure
     * flashes a fixed error. Always PRG-redirects.
     *
     * @param ServerRequestInterface $request The incoming POST request.
     *
     * @return ResponseInterface The redirect response.
     */
    private function triggerFeeder(ServerRequestInterface $request): ResponseInterface
    {
        $treeId = Validator::parsedBody($request)->integer('tree', 0);

        try {
            $tree = $this->treeService()->find($treeId);
        } catch (DomainException) {
            FlashMessages::addMessage(I18N::translate('Unknown tree.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        $root = $this->queueRoot();

        if ($root === null) {
            FlashMessages::addMessage(I18N::translate('Search job could not be queued.'), 'danger');

            return redirect(route(self::ROUTE_NAME));
        }

        $paths = new QueuePaths($root);

        try {
            // ensureLayout() lives INSIDE the try: it throws a RuntimeException when a state directory
            // cannot be created (a non-writable queue root), which must flash + redirect like any other
            // queue failure rather than escape handle() as an unhandled 500 (the PRG contract).
            $paths->ensureLayout();

            $summary = $this->enqueueService($paths)->enqueue(
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
        } catch (RuntimeException) {
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
     * Renders the read-only panel: the prefilled settings, the trees offered for a trigger and the
     * recent queue-job rows. A null/missing queue root yields an empty job list WITHOUT creating the
     * queue layout (a GET is read-only).
     *
     * @return ResponseInterface The rendered panel response.
     */
    private function renderPanel(): ResponseInterface
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
            $this->recentJobTuples(),
        );

        return $this->viewResponse($this->module->name() . '::control-panel', [
            'title' => I18N::translate('Death notices'),
            'view'  => $view,
        ]);
    }

    /**
     * Builds the recent-job tuples for the presenter. The queue root is resolved through the seam; a
     * null/missing root (no queue laid out yet) yields an empty list WITHOUT creating it (read-only).
     *
     * @return list<array{jobId: string, stateKey: string, counts: array<string, int>, finishedAt: string|null}> The recent-job tuples.
     */
    private function recentJobTuples(): array
    {
        $root = $this->queueRoot();

        if ($root === null) {
            return [];
        }

        $paths = new QueuePaths($root);

        $tuples = [];

        foreach ((new QueueClient($paths))->recentJobs(self::RECENT_JOBS) as $jobId => $status) {
            // $status->counts is already narrowed to array<string, int> by QueueClient when it reads the
            // on-disk status.json, so it can pass through to the presenter as-is.
            $tuples[] = [
                'jobId'      => $jobId,
                'stateKey'   => $status->state->value,
                'counts'     => $status->counts,
                'finishedAt' => $status->finishedAt,
            ];
        }

        return $tuples;
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
        if (!ctype_digit($value)) {
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
     * Resolves the running instance's default queue root, or null when it cannot be located. A protected
     * seam so a test can redirect it onto a throwaway queue without pointing the real locator at the
     * test.
     *
     * @return string|null The queue root directory, or null when unresolvable.
     */
    protected function queueRoot(): ?string
    {
        return (new WebtreesInstallLocator(dirname(__DIR__, 2)))->defaultQueueRoot();
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
     * Builds the real enqueue producer over the given queue paths, mirroring the `tools/enqueue.php`
     * wiring exactly. A seam so a test can inject a producer that throws (to pin the trigger path's
     * always-PRG-redirect contract on a DomainException/RuntimeException from the producer).
     *
     * @param QueuePaths $paths The queue path builder rooted at the resolved queue root.
     *
     * @return EnqueueService The wired enqueue producer.
     */
    protected function enqueueService(QueuePaths $paths): EnqueueService
    {
        return EnqueueServiceFactory::create($paths);
    }
}
