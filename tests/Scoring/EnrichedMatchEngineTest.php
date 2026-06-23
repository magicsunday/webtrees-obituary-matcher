<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the enriched engine: it surfaces the three new signals and a list-level config gates them off.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnrichedMatchEngine::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(Gender::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(RelatedPerson::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\SignalScore::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\MatchExplanation::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\ConflictResult::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\ObituaryRecord::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\NameScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\BirthScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\PlaceScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\PlausibilityScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\RelativeScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\AgeScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\CemeteryScorer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Scoring\ConflictDetector::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\NoticeMapper::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\Normalizer::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\ColognePhonetic::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\DeathFactHarvester::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Support\ObituaryNameParser::class)]
final class EnrichedMatchEngineTest extends TestCase
{
    use EnrichedWorkedExampleFixtureTrait;

    /**
     * The enriched engine surfaces the three new signal keys with positive points.
     */
    #[Test]
    public function enrichedProfileSurfacesTheNewSignals(): void
    {
        $explanation = (new EnrichedMatchEngine())->score(
            $this->workedExampleCandidate(),
            $this->workedExampleNotice(),
        );

        self::assertArrayHasKey('relatives', $explanation->signals);
        self::assertArrayHasKey('age', $explanation->signals);
        self::assertArrayHasKey('cemetery', $explanation->signals);
        self::assertSame(25, $explanation->signals['relatives']->score);   // spouse match
        self::assertSame(20, $explanation->signals['age']->score);          // 2024 - 73 = 1951 overlaps birth 1951
        self::assertSame(10, $explanation->signals['cemetery']->score);     // Musterstadt whole-token
    }

    /**
     * The enriched engine harvests the notice's burial facts independently: the exact death date and
     * the cemetery (the funeral date stays absent because the notice's funeral range is unknown).
     */
    #[Test]
    public function enrichedProfileHarvestsCemeteryAlongsideTheDeathDate(): void
    {
        $explanation = (new EnrichedMatchEngine())->score(
            $this->workedExampleCandidate(),
            $this->workedExampleNotice(),
        );

        self::assertSame(
            [
                'deathDate' => '2024-03-01',
                'cemetery'  => 'Waldfriedhof Musterstadt',
            ],
            $explanation->extractedFacts,
        );
    }

    /**
     * Driven with the list-level profile, the enriched signals are capped at 0 (fully gated).
     */
    #[Test]
    public function listLevelConfigGatesTheEnrichedSignalsToZero(): void
    {
        $explanation = (new EnrichedMatchEngine(ScoreConfig::listLevel()))->score(
            $this->workedExampleCandidate(),
            $this->workedExampleNotice(),
        );

        self::assertSame(0, $explanation->signals['relatives']->score);
        self::assertSame(0, $explanation->signals['age']->score);
        self::assertSame(0, $explanation->signals['cemetery']->score);
    }
}
