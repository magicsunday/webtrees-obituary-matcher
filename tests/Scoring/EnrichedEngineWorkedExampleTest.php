<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use MagicSunday\ObituaryMatcher\Domain\Band;
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
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function implode;

/**
 * A full curated worked example through the enriched engine, pinning per-signal scores + the total,
 * then classifying separately exactly like the Phase-1 worked example.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnrichedMatchEngine::class)]
#[UsesClass(Band::class)]
#[UsesClass(Classifier::class)]
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
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\Classification::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\ConflictResult::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\MatchExplanation::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\ObituaryRecord::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\ScoreConfig::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\SignalScore::class)]
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
final class EnrichedEngineWorkedExampleTest extends TestCase
{
    use EnrichedWorkedExampleFixtureTrait;

    /**
     * A strong enriched match: exact name + exact birth + place + plausibility + spouse + age +
     * cemetery, no conflict. Per-signal scores and the total are pinned; the band is classified
     * separately via the Classifier.
     */
    #[Test]
    public function strongEnrichedMatch(): void
    {
        $explanation = (new EnrichedMatchEngine())->score(
            $this->workedExampleCandidate(),
            $this->workedExampleNotice(),
        );

        // Base signals under the enriched profile (all clamped to their rebalanced caps):
        // name 40 -> cap 35, birth 30 -> cap 25, place 12 -> cap 10, plausibility 3+2+5=10 -> cap 10.
        self::assertSame(35, $explanation->signals['name']->score);
        self::assertSame(25, $explanation->signals['birth']->score);
        self::assertSame(10, $explanation->signals['place']->score);
        self::assertSame(10, $explanation->signals['plausibility']->score);

        // Enrichment signals (pinned by the plan):
        self::assertSame(25, $explanation->signals['relatives']->score);
        self::assertSame(20, $explanation->signals['age']->score);
        self::assertSame(10, $explanation->signals['cemetery']->score);
        self::assertStringContainsString('spouse', implode(' ', $explanation->signals['relatives']->reasons));

        // Sum = 35+25+10+10+25+20+10 = 135, no penalty, clamped to the 0..100 total.
        self::assertSame(0, $explanation->conflicts->penalty);
        self::assertSame(100, $explanation->total);

        // The total is the clamped sum; classification is a SEPARATE step (engine never classifies).
        $classification = (new Classifier())->classify($explanation, [$explanation]);
        self::assertSame(Band::Strong, $classification->band);
    }
}
