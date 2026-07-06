<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the scoring-profile constructors and the frozen Phase-1 defaults.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ScoreConfig::class)]
final class ScoreConfigTest extends TestCase
{
    /**
     * The default constructor is exactly the frozen Phase-1 list-level profile.
     */
    #[Test]
    public function defaultEqualsListLevelAndKeepsPhase1Caps(): void
    {
        self::assertEquals(new ScoreConfig(), ScoreConfig::listLevel());

        $listLevel = ScoreConfig::listLevel();
        self::assertSame(45, $listLevel->maxName);
        self::assertSame(30, $listLevel->maxBirth);
        self::assertSame(15, $listLevel->maxPlace);
        self::assertSame(10, $listLevel->maxPlausibility);
        self::assertSame(50, $listLevel->maxPenalty);

        // The enriched caps are OFF at list level, so the base engine can never award enriched points.
        self::assertSame(0, $listLevel->maxRelatives);
        self::assertSame(0, $listLevel->maxAge);
        self::assertSame(0, $listLevel->maxCemetery);
    }

    /**
     * The enriched profile rebalances the base caps and switches on the enrichment caps.
     */
    #[Test]
    public function enrichedProfileHasConservativeCaps(): void
    {
        $enriched = ScoreConfig::enriched();
        self::assertSame(35, $enriched->maxName);
        self::assertSame(25, $enriched->maxBirth);
        self::assertSame(10, $enriched->maxPlace);
        self::assertSame(10, $enriched->maxPlausibility);
        self::assertSame(50, $enriched->maxPenalty);
        self::assertSame(35, $enriched->maxRelatives);
        self::assertSame(20, $enriched->maxAge);
        self::assertSame(10, $enriched->maxCemetery);
    }

    /**
     * enrichedWith() takes the six admin-editable caps as overrides while KEEPING every non-editable
     * enriched value (the plausibility window and the relatives/age/cemetery caps) from the enriched
     * profile — so an operator can retune the base weights without disturbing the enrichment caps.
     */
    #[Test]
    public function enrichedWithOverridesTheEditableCapsAndKeepsTheEnrichedCaps(): void
    {
        $config = ScoreConfig::enrichedWith(40, 28, 12, 8, 44, 6);

        self::assertSame(40, $config->maxName);
        self::assertSame(28, $config->maxBirth);
        self::assertSame(12, $config->maxPlace);
        self::assertSame(8, $config->maxPlausibility);
        self::assertSame(44, $config->maxPenalty);
        self::assertSame(6, $config->ambiguityGap);

        $enriched = ScoreConfig::enriched();
        self::assertSame($enriched->minPlausibleAge, $config->minPlausibleAge);
        self::assertSame($enriched->maxPlausibleAge, $config->maxPlausibleAge);
        self::assertSame($enriched->maxRelatives, $config->maxRelatives);
        self::assertSame($enriched->maxAge, $config->maxAge);
        self::assertSame($enriched->maxCemetery, $config->maxCemetery);
    }

    /**
     * Feeding enrichedWith() the enriched profile's own base caps reproduces the enriched profile
     * verbatim — the invariant that lets the editable-weight defaults leave live scoring unchanged.
     */
    #[Test]
    public function enrichedWithAtTheEnrichedDefaultsEqualsTheEnrichedProfile(): void
    {
        self::assertEquals(
            ScoreConfig::enriched(),
            ScoreConfig::enrichedWith(35, 25, 10, 10, 50, 10),
        );
    }
}
