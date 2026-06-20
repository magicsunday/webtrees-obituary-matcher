<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the result value objects ConflictReason/Result, Band and ScoreConfig.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ConflictSeverity::class)]
#[CoversClass(ConflictReason::class)]
#[CoversClass(ConflictResult::class)]
#[CoversClass(Band::class)]
#[CoversClass(ScoreConfig::class)]
final class ResultValueObjectsTest extends TestCase
{
    /**
     * Verifies that ConflictResult detects a hard conflict via ConflictReason.
     */
    #[Test]
    public function conflictReportsHardConflict(): void
    {
        $with = new ConflictResult(
            30,
            [new ConflictReason('birth', '1938', '1950', ConflictSeverity::Hard)],
        );
        $without = new ConflictResult(
            10,
            [new ConflictReason('place', 'Musterstadt', 'Beispielstadt', ConflictSeverity::Soft)],
        );

        self::assertTrue($with->hasHardConflict());
        self::assertFalse($without->hasHardConflict());
    }

    /**
     * Verifies that Band::value() returns lowercase labels.
     */
    #[Test]
    public function bandLabelsAreLowercase(): void
    {
        self::assertSame('strong', Band::Strong->value());
        self::assertSame('none', Band::None->value());
    }

    /**
     * Verifies that ScoreConfig defaults to Phase-1 weights.
     */
    #[Test]
    public function scoreConfigDefaultsToPhaseOneWeights(): void
    {
        $config = new ScoreConfig();
        self::assertSame(45, $config->maxName);
        self::assertSame(10, $config->ambiguityGap);
    }

    /**
     * Verifies that ConflictSeverity::value() returns the lowercase string.
     */
    #[Test]
    public function conflictSeverityValuesAreLowercase(): void
    {
        self::assertSame('hard', ConflictSeverity::Hard->value());
        self::assertSame('soft', ConflictSeverity::Soft->value());
    }
}
