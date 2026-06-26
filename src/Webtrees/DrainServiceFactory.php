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
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;

/**
 * The single composition root for the drain object graph. Both the headless `tools/drain.php` CLI
 * adapter and any future drain consumer assemble the same {@see DrainService} graph over a queue
 * root; this factory holds that wiring once, mirroring {@see EnqueueServiceFactory} on the producer
 * side, so the response-size cap stays in one named constant ({@see QueueLimits}). The per-tree match
 * store is NOT wired here — {@see DrainService} builds it per job through its {@see MatchStoreFactory}
 * seam, keeping the ingest store-agnostic.
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
     * Wires the full drain object graph over the given queue paths. The same {@see QueuePaths}
     * instance is threaded into every collaborator so they read and write the SAME queue root.
     *
     * @param QueuePaths $paths The queue path builder rooted at the resolved queue root.
     *
     * @return DrainService The wired drain consumer.
     */
    public static function create(QueuePaths $paths): DrainService
    {
        return new DrainService(
            $paths,
            new QueueClient($paths),
            new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            new CandidateRepository(),
            IngestServiceFactory::create($paths),
            new TreeService(new GedcomImportService()),
        );
    }
}
