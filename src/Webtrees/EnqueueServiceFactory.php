<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

/**
 * The single composition root for the producer object graph. Both the headless `tools/enqueue.php` CLI
 * adapter and the admin {@see ObituaryControlPanelHandler} trigger path assemble the very same
 * {@see EnqueueService} graph over a queue root; this factory holds that wiring once so the two consumers
 * stay byte-identical, and the transport (file or REST) is selected from the {@see FinderConnection}
 * through {@see JobTransportFactory}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class EnqueueServiceFactory
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Wires the full producer object graph over the given queue paths and finder connection. The
     * transport (file or REST) is selected by the connection through {@see JobTransportFactory}; the
     * connection defaults to the file-drop queue, so every existing caller passing only the queue paths
     * keeps the file transport with no change. The REST ledger root is passed in explicitly (it is
     * resolved by the caller, never derived from {@see QueuePaths}).
     *
     * @param QueuePaths            $paths           The queue path builder rooted at the resolved queue root.
     * @param FinderConnection|null $connection      The finder connection selecting the transport, or null
     *                                               for the default file-drop queue.
     * @param string|null           $restPendingRoot The REST in-flight ledger root, required when the
     *                                               connection selects the REST transport.
     *
     * @return EnqueueService The wired enqueue producer.
     */
    public static function create(
        QueuePaths $paths,
        ?FinderConnection $connection = null,
        ?string $restPendingRoot = null,
    ): EnqueueService {
        return new EnqueueService(
            new CandidateRepository(),
            new FeederRequestFactory(new QueryGenerator()),
            new UrlHostNormalizer(),
            new TreeService(new GedcomImportService()),
            JobTransportFactory::create($paths, $connection ?? FinderConnection::file(), $restPendingRoot),
        );
    }
}
