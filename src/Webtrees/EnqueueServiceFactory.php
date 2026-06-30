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
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

/**
 * The single composition root for the producer object graph. Both the headless `tools/enqueue.php` CLI
 * adapter and the admin {@see ObituaryControlPanelHandler} trigger path assemble the very same
 * {@see EnqueueService} graph; this factory holds that wiring once so the two consumers stay
 * byte-identical, and the REST transport is wired from the {@see FinderConnection} through
 * {@see JobTransportFactory}.
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
     * Wires the full producer object graph over the given finder connection and REST ledger root. The
     * REST transport is wired from the connection through {@see JobTransportFactory}; the ledger root is
     * passed in explicitly (resolved by the caller).
     *
     * @param FinderConnection $connection      The REST finder connection.
     * @param string           $restPendingRoot The REST in-flight ledger root.
     *
     * @return EnqueueService The wired enqueue producer.
     */
    public static function create(FinderConnection $connection, string $restPendingRoot): EnqueueService
    {
        return new EnqueueService(
            new CandidateRepository(),
            new FeederRequestFactory(new QueryGenerator()),
            new UrlHostNormalizer(),
            new TreeService(new GedcomImportService()),
            JobTransportFactory::create($connection, $restPendingRoot),
        );
    }
}
