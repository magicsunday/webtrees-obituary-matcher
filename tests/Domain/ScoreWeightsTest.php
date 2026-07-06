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
use MagicSunday\ObituaryMatcher\Domain\ScoreWeights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the editable-scoring-weights value object: its enriched-aligned defaults, the lenient
 * preference reader and the projection back into a {@see ScoreConfig}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ScoreWeights::class)]
final class ScoreWeightsTest extends TestCase
{
    /**
     * The defaults project to EXACTLY the enriched profile, so an install that never touches the
     * weights keeps scoring identical to before the setting existed.
     */
    #[Test]
    public function defaultsProjectToExactlyTheEnrichedProfile(): void
    {
        self::assertEquals(ScoreConfig::enriched(), ScoreWeights::defaults()->toScoreConfig());
    }

    /**
     * Each cap is read through its own preference key.
     */
    #[Test]
    public function fromReaderReadsEachCapThroughItsPreferenceKey(): void
    {
        $stored = [
            'score_max_name'         => '40',
            'score_max_birth'        => '20',
            'score_max_place'        => '12',
            'score_max_plausibility' => '8',
            'score_max_penalty'      => '44',
            'score_ambiguity_gap'    => '6',
        ];

        $weights = ScoreWeights::fromReader(
            static fn (string $key, string $default): string => $stored[$key] ?? $default,
        );

        self::assertSame(40, $weights->maxName);
        self::assertSame(20, $weights->maxBirth);
        self::assertSame(12, $weights->maxPlace);
        self::assertSame(8, $weights->maxPlausibility);
        self::assertSame(44, $weights->maxPenalty);
        self::assertSame(6, $weights->ambiguityGap);
    }

    /**
     * A never-persisted value (the reader returns the passed default) and a non-integer stored value
     * both fall back to the enriched default rather than corrupting the config.
     */
    #[Test]
    public function fromReaderFallsBackToTheDefaultForAMissingOrUnusableValue(): void
    {
        self::assertEquals(
            ScoreWeights::defaults(),
            ScoreWeights::fromReader(static fn (string $key, string $default): string => $default),
        );

        self::assertEquals(
            ScoreWeights::defaults(),
            ScoreWeights::fromReader(static fn (string $key, string $default): string => 'not-a-number'),
        );
    }

    /**
     * A tampered, out-of-range stored value is clamped into the shared bounds rather than trusted.
     */
    #[Test]
    public function fromReaderClampsAnOutOfRangeValueIntoBounds(): void
    {
        $weights = ScoreWeights::fromReader(static fn (string $key, string $default): string => '9999');

        self::assertSame(100, $weights->maxName);
        self::assertSame(100, $weights->maxPenalty);
        self::assertSame(100, $weights->ambiguityGap);
    }
}
