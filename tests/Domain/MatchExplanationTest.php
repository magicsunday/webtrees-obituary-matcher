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
use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\RunnerUp;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the MatchExplanation, ClassifiedMatch and RunnerUp array projections.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(MatchExplanation::class)]
#[CoversClass(ClassifiedMatch::class)]
#[CoversClass(RunnerUp::class)]
#[UsesClass(SignalScore::class)]
#[UsesClass(ConflictResult::class)]
#[UsesClass(ConflictReason::class)]
#[UsesClass(ConflictSeverity::class)]
#[UsesClass(Classification::class)]
#[UsesClass(Band::class)]
final class MatchExplanationTest extends TestCase
{
    /**
     * Builds a representative MatchExplanation fixture without conflicts.
     *
     * @return MatchExplanation
     */
    private function explanation(): MatchExplanation
    {
        return new MatchExplanation(
            'I1234',
            'https://example.test/x',
            78,
            [
                'name'         => new SignalScore(34, 45, ['married name matches']),
                'birth'        => new SignalScore(16, 30, ['same year']),
                'place'        => new SignalScore(8, 15, ['within region']),
                'plausibility' => new SignalScore(5, 10, ['plausible age']),
            ],
            new ConflictResult(0, []),
            ['deathDate' => '2023-09-04'],
        );
    }

    /**
     * Verifies that the pair array includes signals with max, personId, score, and extractedFacts.
     */
    #[Test]
    public function pairArrayProjectsConflictsAsNegativeScore(): void
    {
        $array = $this->explanation()->toArray();

        self::assertSame('I1234', $array['personId']);
        self::assertSame(78, $array['score']);

        $signals = $array['signals'];

        $name = $signals['name'];
        self::assertArrayHasKey('max', $name);
        self::assertSame(34, $name['score']);
        self::assertSame(45, $name['max']);

        $conflicts = $signals['conflicts'];
        self::assertSame(0, $conflicts['score']);
        self::assertSame([], $conflicts['reasons']);

        self::assertFalse($array['hardConflict']);

        self::assertSame('2023-09-04', $array['extractedFacts']['deathDate']);

        self::assertArrayNotHasKey('classification', $array);
    }

    /**
     * Verifies that a non-zero penalty appears as a negative conflict score.
     */
    #[Test]
    public function negativePenaltyShowsAsNegativeConflictScore(): void
    {
        $explanation = new MatchExplanation(
            'I1',
            'https://example.test/y',
            48,
            [
                'name'         => new SignalScore(45, 45, ['exact']),
                'birth'        => new SignalScore(0, 30, []),
                'place'        => new SignalScore(0, 15, []),
                'plausibility' => new SignalScore(3, 10, []),
            ],
            new ConflictResult(30, [new ConflictReason('birth', '1938', '1950', ConflictSeverity::Hard)]),
            [],
        );

        $array = $explanation->toArray();

        $signals   = $array['signals'];
        $conflicts = $signals['conflicts'];

        self::assertSame(-30, $conflicts['score']);
        self::assertTrue($array['hardConflict']);

        $reasons = $conflicts['reasons'];
        self::assertCount(1, $reasons);

        $reason = $reasons[0];
        self::assertIsArray($reason);
        self::assertSame('birth', $reason['field']);
        self::assertSame('1938', $reason['treeValue']);
        self::assertSame('1950', $reason['obituaryValue']);
        self::assertSame('hard', $reason['severity']);
    }

    /**
     * Verifies that ClassifiedMatch adds classification, ambiguous, review, and runnerUp keys.
     */
    #[Test]
    public function classifiedMatchAddsClassification(): void
    {
        $classified = new ClassifiedMatch(
            $this->explanation(),
            new Classification(Band::Probable, false, ['score 78']),
        );

        $array = $classified->toArray();
        self::assertSame('probable', $array['classification']);
        self::assertFalse($array['ambiguous']);
        self::assertNull($array['review']);
        self::assertNull($array['runnerUp']);
        self::assertSame(78, $array['score']);
    }

    /**
     * Verifies that RunnerUp serialises when provided and is null by default.
     */
    #[Test]
    public function runnerUpSerialisesWhenProvided(): void
    {
        $runnerUp = new RunnerUp('I9', 60, 'probable', 'Hans Mustermann', 1940, 'Musterstadt');

        $classified = new ClassifiedMatch(
            $this->explanation(),
            new Classification(Band::Strong, false, []),
            $runnerUp,
            'pending',
        );

        $array         = $classified->toArray();
        $runnerUpArray = $array['runnerUp'];
        self::assertIsArray($runnerUpArray);
        self::assertSame('I9', $runnerUpArray['personId']);
        self::assertSame(60, $runnerUpArray['score']);
        self::assertSame('probable', $runnerUpArray['classification']);
        self::assertSame('Hans Mustermann', $runnerUpArray['name']);
        self::assertSame(1940, $runnerUpArray['birthYear']);
        self::assertSame('Musterstadt', $runnerUpArray['birthPlace']);
        self::assertSame('pending', $array['review']);
    }
}
