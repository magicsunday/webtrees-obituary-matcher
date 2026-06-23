<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Support\ColognePhonetic;
use MagicSunday\ObituaryMatcher\Support\DeathFactHarvester;
use MagicSunday\ObituaryMatcher\Support\NoticeMapper;
use MagicSunday\ObituaryMatcher\Support\PhoneticEncoder;

use function max;
use function min;

/**
 * Scores one (candidate, death-notice) pair into an explainable result using the enriched profile:
 * the four Phase-1 base scorers (re-composed at the enriched weights via NoticeMapper) plus three
 * enrichment signals — relatives, age, cemetery — minus the unchanged Phase-1 conflict penalty.
 * Phase-1 `MatchEngine` is untouched; defaulting the config to {@see ScoreConfig::enriched()} keeps
 * the two engines independent.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class EnrichedMatchEngine
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
     * The relatives signal scorer.
     */
    private RelativeScorer $relativeScorer;

    /**
     * The age signal scorer.
     */
    private AgeScorer $ageScorer;

    /**
     * The cemetery signal scorer.
     */
    private CemeteryScorer $cemeteryScorer;

    /**
     * The sole source of negative evidence (unchanged from Phase-1).
     */
    private ConflictDetector $conflictDetector;

    /**
     * Constructor.
     *
     * @param ScoreConfig|null     $config   The scoring configuration, defaulting to the enriched profile.
     * @param PhoneticEncoder|null $phonetic The phonetic encoder, defaulting to Cologne phonetics.
     */
    public function __construct(?ScoreConfig $config = null, ?PhoneticEncoder $phonetic = null)
    {
        $config   ??= ScoreConfig::enriched();
        $phonetic ??= new ColognePhonetic();

        $this->nameScorer         = new NameScorer($phonetic, $config);
        $this->birthScorer        = new BirthScorer($config);
        $this->placeScorer        = new PlaceScorer($config);
        $this->plausibilityScorer = new PlausibilityScorer($config);
        $this->relativeScorer     = new RelativeScorer($config);
        $this->ageScorer          = new AgeScorer($config);
        $this->cemeteryScorer     = new CemeteryScorer($config);
        $this->conflictDetector   = new ConflictDetector($config);
    }

    /**
     * Scores a single (candidate, death-notice) pair into an explainable result.
     *
     * @param PersonCandidate   $candidate The tree candidate.
     * @param DeathNoticeRecord $notice    The enriched death notice.
     *
     * @return MatchExplanation The explainable pair result with a clamped 0..100 total.
     */
    public function score(PersonCandidate $candidate, DeathNoticeRecord $notice): MatchExplanation
    {
        $record = NoticeMapper::toObituaryRecord($notice);

        $signals = [
            'name'         => $this->nameScorer->score($candidate->name, $record->parsedName),
            'birth'        => $this->birthScorer->score($candidate->birth, $record->birth),
            'place'        => $this->placeScorer->score($candidate, $record->place),
            'plausibility' => $this->plausibilityScorer->score($candidate, $record),
            'relatives'    => $this->relativeScorer->score($candidate, $notice->relatives),
            'age'          => $this->ageScorer->score($candidate->birth, $notice->age, $notice->death),
            'cemetery'     => $this->cemeteryScorer->score($candidate, $notice->cemetery),
        ];

        $conflicts = $this->conflictDetector->detect($candidate, $record);

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
            DeathFactHarvester::harvestFromNotice($notice),
        );
    }
}
