<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Support\ColognePhonetic;
use MagicSunday\ObituaryMatcher\Support\PhoneticEncoder;

use function max;
use function min;
use function sprintf;

/**
 * Scores one (candidate, obituary) pair into an explainable MatchExplanation by
 * summing the four positive signals and subtracting the conflict penalty.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class MatchEngine
{
    /**
     * The lowest possible total score after clamping.
     */
    private const int MIN_TOTAL = 0;

    /**
     * The highest possible total score after clamping.
     */
    private const int MAX_TOTAL = 100;

    /**
     * The name signal scorer.
     */
    private NameScorer $nameScorer;

    /**
     * The birth-date signal scorer.
     */
    private BirthScorer $birthScorer;

    /**
     * The place signal scorer.
     */
    private PlaceScorer $placeScorer;

    /**
     * The plausibility signal scorer.
     */
    private PlausibilityScorer $plausibilityScorer;

    /**
     * The sole source of negative evidence.
     */
    private ConflictDetector $conflictDetector;

    /**
     * Constructor.
     *
     * @param ScoreConfig|null     $config   The scoring configuration, defaulting to the standard weights.
     * @param PhoneticEncoder|null $phonetic The phonetic encoder, defaulting to Cologne phonetics.
     */
    public function __construct(?ScoreConfig $config = null, ?PhoneticEncoder $phonetic = null)
    {
        $config   ??= new ScoreConfig();
        $phonetic ??= new ColognePhonetic();

        $this->nameScorer         = new NameScorer($phonetic, $config);
        $this->birthScorer        = new BirthScorer($config);
        $this->placeScorer        = new PlaceScorer($config);
        $this->plausibilityScorer = new PlausibilityScorer($config);
        $this->conflictDetector   = new ConflictDetector($config);
    }

    /**
     * Scores a single (candidate, obituary) pair into an explainable result.
     *
     * @param PersonCandidate $candidate The tree candidate.
     * @param ObituaryRecord  $notice    The obituary notice.
     *
     * @return MatchExplanation The explainable pair result with a clamped 0..100 total.
     */
    public function score(PersonCandidate $candidate, ObituaryRecord $notice): MatchExplanation
    {
        $signals = [
            'name'         => $this->nameScorer->score($candidate->name, $notice->parsedName),
            'birth'        => $this->birthScorer->score($candidate->birth, $notice->birth),
            'place'        => $this->placeScorer->score($candidate, $notice->place),
            'plausibility' => $this->plausibilityScorer->score($candidate, $notice),
        ];

        $conflicts = $this->conflictDetector->detect($candidate, $notice);

        $positive = 0;

        foreach ($signals as $signal) {
            $positive += $signal->score;
        }

        $total = max(self::MIN_TOTAL, min(self::MAX_TOTAL, $positive - $conflicts->penalty));

        return new MatchExplanation(
            $candidate->id,
            $notice->url,
            $total,
            $signals,
            $conflicts,
            $this->extractFacts($notice),
        );
    }

    /**
     * Harvests facts from the notice, currently the exact death date.
     *
     * @param ObituaryRecord $notice The obituary notice.
     *
     * @return array<string,string> Facts to harvest (deathDate when the death is exact).
     */
    private function extractFacts(ObituaryRecord $notice): array
    {
        if (
            !$notice->death->isExact()
            || !$notice->death->earliest instanceof DateValue
        ) {
            return [];
        }

        $date = $notice->death->earliest;

        return [
            'deathDate' => sprintf('%04d-%02d-%02d', $date->year, $date->month ?? 1, $date->day ?? 1),
        ];
    }
}
