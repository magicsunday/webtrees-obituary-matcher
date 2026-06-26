<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;

/**
 * The composition root for the {@see IngestService} apex. The scoring engine is wired HERE, inside the
 * Matching layer that legitimately composes Scoring and Queue, so the Webtrees adapter never reaches
 * into the pure scoring engine to assemble the ingest by hand — it drives the apex through this factory
 * instead (see the architecture rule webtreesAdapterDoesNotDependOnTheScoringEngine). The same
 * {@see QueuePaths} instance is threaded into the {@see ResponseReader} so the reader resolves the SAME
 * queue root the rest of the drain graph reads and writes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class IngestServiceFactory
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Wires the ingest apex over the given queue paths, composing the enriched scoring engine and the
     * band classifier behind the Matching boundary.
     *
     * @param QueuePaths $paths The queue path builder rooted at the resolved queue root.
     *
     * @return IngestService The wired ingest apex.
     */
    public static function create(QueuePaths $paths): IngestService
    {
        return new IngestService(
            new ResponseReader($paths),
            new EnrichedMatchEngine(),
            new Classifier(),
        );
    }
}
