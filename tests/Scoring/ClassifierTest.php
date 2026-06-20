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
use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the classifier that turns a scored result set into a band and ambiguity flag.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(Classifier::class)]
#[UsesClass(Band::class)]
#[UsesClass(Classification::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class ClassifierTest extends TestCase
{
    /**
     * Builds a minimal match explanation with the given total and optional hard conflict.
     *
     * @param int  $total        The clamped total score.
     * @param bool $hardConflict Whether to attach a hard conflict.
     *
     * @return MatchExplanation The synthetic result.
     */
    private function explanation(int $total, bool $hardConflict = false): MatchExplanation
    {
        $conflicts = $hardConflict
            ? new ConflictResult(30, [new ConflictReason('Geburtsdatum', 'a', 'b', ConflictSeverity::Hard)])
            : new ConflictResult(0, []);

        return new MatchExplanation(
            'I1',
            'https://example.test/x',
            $total,
            ['name' => new SignalScore($total, 45, [])],
            $conflicts,
            [],
        );
    }

    /**
     * The band is derived directly from the total score thresholds.
     */
    #[Test]
    public function bandsByScore(): void
    {
        $classifier = new Classifier();

        self::assertSame(Band::Strong, $classifier->classify($this->explanation(90), [$this->explanation(90)])->band);
        self::assertSame(Band::Probable, $classifier->classify($this->explanation(75), [$this->explanation(75)])->band);
        self::assertSame(Band::None, $classifier->classify($this->explanation(20), [$this->explanation(20)])->band);
    }

    /**
     * A hard conflict caps an otherwise strong band at Possible.
     */
    #[Test]
    public function hardConflictCapsAtPossible(): void
    {
        $best   = $this->explanation(90, true);
        $result = (new Classifier())->classify($best, [$best]);

        self::assertSame(Band::Possible, $result->band);
    }

    /**
     * A runner-up within the ambiguity gap flags the result as ambiguous.
     */
    #[Test]
    public function ambiguousWhenGapBelowTen(): void
    {
        $best   = $this->explanation(82);
        $second = $this->explanation(74); // Gap 8 -> ambiguous.

        self::assertTrue((new Classifier())->classify($best, [$best, $second])->ambiguous);
    }

    /**
     * A runner-up exactly at the ambiguity gap is not ambiguous.
     */
    #[Test]
    public function notAmbiguousWhenGapIsExactlyTen(): void
    {
        $best   = $this->explanation(80);
        $second = $this->explanation(70); // Gap 10 -> not ambiguous.

        self::assertFalse((new Classifier())->classify($best, [$best, $second])->ambiguous);
    }
}
