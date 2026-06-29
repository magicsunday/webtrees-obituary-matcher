<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;

/**
 * The composition root for the {@see IngestService} apex. The scoring engine is wired HERE, inside the
 * Matching layer that legitimately composes Scoring and Queue, so the Webtrees adapter never reaches
 * into the pure scoring engine to assemble the ingest by hand — it drives the apex through this factory
 * instead (see the architecture rule webtreesAdapterDoesNotDependOnTheScoringEngine).
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
     * Wires the ingest apex, composing the enriched scoring engine and the band classifier behind the
     * Matching boundary. The ingest is transport-agnostic and is handed already-validated notices by the
     * caller, so it no longer reads the response itself and needs no queue paths.
     *
     * @return IngestService The wired ingest apex.
     */
    public static function create(): IngestService
    {
        return new IngestService(new EnrichedMatchEngine(), new Classifier());
    }
}
