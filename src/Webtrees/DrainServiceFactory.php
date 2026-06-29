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
use MagicSunday\ObituaryMatcher\Matching\IngestServiceFactory;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;

/**
 * The single composition root for the drain object graph. Both the headless `tools/drain.php` CLI
 * adapter and any future drain consumer assemble the same {@see DrainService} graph over a queue
 * root; this factory holds that wiring once, mirroring {@see EnqueueServiceFactory} on the producer
 * side, and the transport (file or REST) is selected from the {@see FinderConnection} through
 * {@see JobTransportFactory}. The per-tree match store is NOT wired here — {@see DrainService} builds
 * it per job through its {@see MatchStoreFactory} seam, keeping the ingest store-agnostic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class DrainServiceFactory
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Wires the full drain object graph over the given queue paths and finder connection. The transport
     * (file or REST) is selected by the connection through {@see JobTransportFactory}; the connection
     * defaults to the file-drop queue, so every existing caller passing only the queue paths keeps the
     * file transport with no change. The REST ledger root is passed in explicitly (resolved by the
     * caller, never derived from {@see QueuePaths}).
     *
     * @param QueuePaths            $paths           The queue path builder rooted at the resolved queue root.
     * @param FinderConnection|null $connection      The finder connection selecting the transport, or null
     *                                               for the default file-drop queue.
     * @param string|null           $restPendingRoot The REST in-flight ledger root, required when the
     *                                               connection selects the REST transport.
     *
     * @return DrainService The wired drain consumer.
     */
    public static function create(
        QueuePaths $paths,
        ?FinderConnection $connection = null,
        ?string $restPendingRoot = null,
    ): DrainService {
        return new DrainService(
            new CandidateRepository(),
            IngestServiceFactory::create(),
            new TreeService(new GedcomImportService()),
            JobTransportFactory::create($paths, $connection ?? FinderConnection::file(), $restPendingRoot),
        );
    }
}
