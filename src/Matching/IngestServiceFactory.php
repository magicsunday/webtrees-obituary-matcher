<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
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
     * The SAME scoring configuration drives both the engine (per-signal caps) and the classifier
     * (ambiguity gap), so the admin-editable weights stay coherent across the whole scoring pass. A null
     * config defaults to the enriched profile, preserving the pre-setting behaviour.
     *
     * @param ScoreConfig|null $scoreConfig The scoring configuration, defaulting to the enriched profile.
     *
     * @return IngestService The wired ingest apex.
     */
    public static function create(?ScoreConfig $scoreConfig = null): IngestService
    {
        $scoreConfig ??= ScoreConfig::enriched();

        return new IngestService(
            new EnrichedMatchEngine($scoreConfig),
            new Classifier($scoreConfig),
        );
    }
}
