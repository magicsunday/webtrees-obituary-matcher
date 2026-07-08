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
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function dirname;
use function redirect;
use function route;
use function sprintf;

/**
 * Enqueues a single, manager-chosen individual into the death-notice search — the per-person counterpart
 * of the admin control panel's auto-selecting trigger. A manager viewing a person's obituary tab submits
 * this POST to search for THAT individual specifically (a candidate whose death date is still missing but
 * whom the auto-run has not yet reached, or one worth a manual re-check), and the same producer path
 * {@see EnqueueService::enqueueOne()} assembles and submits the finder job.
 *
 * Manager-gated (enqueuing consumes finder resources and reveals the person to the external finder) and
 * POST-only; it always PRG-redirects back to the individual page with a flash. The finder-connection and
 * REST-ledger resolution mirrors {@see ObituaryControlPanelHandler} (both read the module's own
 * preferences); the three resolvers are protected seams so a test can drive them over doubles.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class EnqueuePersonHandler implements RequestHandlerInterface
{
    /**
     * The route name used to register and to build links to this handler.
     */
    public const string ROUTE_NAME = 'obituary-matcher-enqueue-person';

    /**
     * The route URL pattern (the {tree} segment supplies the Validator tree attribute).
     */
    public const string ROUTE_URL = '/tree/{tree}/obituary-enqueue/{xref}';

    /**
     * Constructor.
     *
     * @param ObituaryMatcherModule $module The module instance, for its getPreference finder settings.
     */
    public function __construct(
        private readonly ObituaryMatcherModule $module,
    ) {
    }

    /**
     * Handles the enqueue-person POST: manager-gates, resolves the (visible) individual, resolves the
     * finder connection + REST ledger, and enqueues exactly that person. Always PRG-redirects to the
     * individual page with a flash describing the outcome.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return ResponseInterface The redirect response.
     *
     * @throws HttpAccessDeniedException When the principal is not a manager of the tree.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::attributes($request)->isXref()->string('xref');

        $individualUrl = route(IndividualPage::class, ['tree' => $tree->name(), 'xref' => $xref]);

        // Manager-only: an enqueue consumes finder resources and reveals the person to the external finder.
        if (!Auth::isManager($tree, $user)) {
            throw new HttpAccessDeniedException();
        }

        // A stray GET (or any non-POST) never runs the mutating enqueue; it simply bounces back.
        if ($request->getMethod() !== RequestMethodInterface::METHOD_POST) {
            return redirect($individualUrl);
        }

        // A manager's explicit "search again" bypasses the negative-memory re-search policy (§5.2d): a
        // person recorded as a recent genuine miss is re-enqueued anyway. Absent the flag the policy holds.
        $override = Validator::parsedBody($request)->boolean('override', false);

        // The individual must exist and be visible to the principal (privacy gate) before we enqueue it.
        $individual = Registry::individualFactory()->make($xref, $tree);
        Auth::checkIndividualAccess($individual, false, false);

        $connection = $this->finderConnection();

        if (!$connection instanceof FinderConnection) {
            FlashMessages::addMessage(I18N::translate('Configure the finder connection first.'), 'danger');

            return redirect($individualUrl);
        }

        $restPendingRoot = $this->restPendingRoot();

        if ($restPendingRoot === null) {
            FlashMessages::addMessage(I18N::translate('The finder storage location is unavailable.'), 'danger');

            return redirect($individualUrl);
        }

        try {
            $summary = $this->enqueueService($connection, $restPendingRoot)->enqueueOne(
                $tree->id(),
                $xref,
                I18N::languageTag(),
                $override,
            );
        } catch (Throwable $throwable) {
            // Any enqueue failure (transport, a vanished tree) flashes + redirects — the handler contract
            // is that this path never escapes handle() as a 500. Log the person + the exception CLASS (not
            // its message, which a transport error can embed the finder URL/token into) so the failure is
            // debuggable without leaking the finder connection into the log — the WorklistHandler/
            // RevertService convention.
            Log::addErrorLog(sprintf('Obituary matcher: enqueue for person %s failed (%s).', $xref, $throwable::class));

            FlashMessages::addMessage(I18N::translate('Search job could not be queued.'), 'danger');

            return redirect($individualUrl);
        }

        if ($summary->jobId !== null) {
            FlashMessages::addMessage(I18N::translate('Search job queued (%s).', $summary->jobId), 'success');
        } elseif ($summary->suppressed > 0) {
            FlashMessages::addMessage(
                I18N::translate('This person was recently searched and nothing was found, so the search was not repeated. Use “Search again” to search now anyway.'),
                'info',
            );
        } else {
            FlashMessages::addMessage(
                I18N::translate('That person could not be queued: unknown, unsearchable, or already awaiting results.'),
                'warning',
            );
        }

        return redirect($individualUrl);
    }

    /**
     * Wires the enqueue producer over the given connection and REST ledger root. A protected seam so a
     * test can return a producer over a scripted transport.
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
     * Reads the finder connection from module preferences, or null when it is not configured. Mirrors
     * {@see ObituaryControlPanelHandler::finderConnection()}: the REST endpoint activates ONLY on the
     * `finder_transport === 'rest'` consent a successful save recorded; any other stored value is treated
     * as not configured. A protected seam so a test can supply a connection directly.
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
