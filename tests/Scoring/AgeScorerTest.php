<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\AgeScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the age scorer that compares the death-minus-age implied birth window to the candidate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(AgeScorer::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class AgeScorerTest extends TestCase
{
    private function scorer(): AgeScorer
    {
        return new AgeScorer(ScoreConfig::enriched());
    }

    /**
     * Death 2024 + age 86 implies birth window 1937-1938; a candidate born 1938 overlaps → 20.
     */
    #[Test]
    public function overlapScoresTwenty(): void
    {
        $signal = $this->scorer()->score(DateRange::year(1938), 86, DateRange::year(2024));
        self::assertSame(20, $signal->score);
        self::assertStringContainsString('age', implode(' ', $signal->reasons));
    }

    /**
     * A one-year gap (candidate 1939 vs window 1937-1938) is a near miss → 10.
     */
    #[Test]
    public function gapOfOneScoresTen(): void
    {
        self::assertSame(10, $this->scorer()->score(DateRange::year(1939), 86, DateRange::year(2024))->score);
    }

    /**
     * A two-year gap (candidate 1940) is still a near miss → 10.
     */
    #[Test]
    public function gapOfTwoScoresTen(): void
    {
        self::assertSame(10, $this->scorer()->score(DateRange::year(1940), 86, DateRange::year(2024))->score);
    }

    /**
     * A three-year gap (candidate 1934) is out of range → 0 (NOT a conflict in 2b).
     */
    #[Test]
    public function gapOfThreeScoresZero(): void
    {
        self::assertSame(0, $this->scorer()->score(DateRange::year(1934), 86, DateRange::year(2024))->score);
    }

    /**
     * Missing age, missing death year, or unknown candidate birth all score zero.
     */
    #[Test]
    public function missingDataScoresZero(): void
    {
        self::assertSame(0, $this->scorer()->score(DateRange::year(1938), null, DateRange::year(2024))->score);
        self::assertSame(0, $this->scorer()->score(DateRange::year(1938), 86, DateRange::unknown())->score);
        self::assertSame(0, $this->scorer()->score(DateRange::unknown(), 86, DateRange::year(2024))->score);
    }
}
