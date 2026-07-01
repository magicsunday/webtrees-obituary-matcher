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
use MagicSunday\ObituaryMatcher\Support\FinderConnection;

/**
 * The single composition root for the drain object graph. Both the headless `tools/drain.php` CLI
 * adapter and any future drain consumer assemble the same {@see DrainService} graph; this factory holds
 * that wiring once, mirroring {@see EnqueueServiceFactory} on the producer side, and the REST transport
 * is wired from the {@see FinderConnection} through {@see JobTransportFactory}. The per-tree match store
 * is NOT wired here — {@see DrainService} builds it per job through its {@see MatchStoreFactory} seam,
 * keeping the ingest store-agnostic.
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
     * Wires the full drain object graph over the given finder connection and REST ledger root. The REST
     * transport is wired from the connection through {@see JobTransportFactory}; the ledger root is
     * passed in explicitly (resolved by the caller).
     *
     * @param FinderConnection $connection      The REST finder connection.
     * @param string           $restPendingRoot The REST in-flight ledger root.
     *
     * @return DrainService The wired drain consumer.
     */
    public static function create(FinderConnection $connection, string $restPendingRoot): DrainService
    {
        return new DrainService(
            new CandidateRepository(),
            IngestServiceFactory::create(),
            new TreeService(new GedcomImportService()),
            JobTransportFactory::create($connection, $restPendingRoot),
        );
    }
}
