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
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\CemeteryScorer;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the cemetery scorer that whole-token-matches the burial place against known places.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CemeteryScorer::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(Gender::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
#[UsesClass(Normalizer::class)]
final class CemeteryScorerTest extends TestCase
{
    /**
     * @param list<Place> $places The candidate's residences.
     */
    private function candidate(array $places): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::unknown(),
            null,
            $places,
            DateRange::unknown(),
        );
    }

    /**
     * The candidate place appears as a whole token of the free-text cemetery name → 10.
     */
    #[Test]
    public function townWholeTokenMatchScoresTen(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Musterstadt')]),
            new Place('Waldfriedhof Musterstadt'),
        );

        self::assertSame(10, $signal->score);
        self::assertStringContainsString('cemetery', implode(' ', $signal->reasons));
    }

    /**
     * A region whole-token match scores the region weight (6).
     */
    #[Test]
    public function regionWholeTokenMatchScoresSix(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Dorf', null, 'Musterregion')]),
            new Place('Friedhof Musterregion'),
        );

        self::assertSame(6, $signal->score);
    }

    /**
     * A short candidate place name (< 4 chars) must not spuriously match inside the cemetery text.
     */
    #[Test]
    public function shortPlaceNameDoesNotSpuriouslyMatch(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Au')]),
            new Place('Trauerhalle Auenwald'),
        );

        self::assertSame(0, $signal->score);
    }

    /**
     * A null cemetery and a non-matching cemetery both score zero.
     */
    #[Test]
    public function nullOrNoMatchScoresZero(): void
    {
        self::assertSame(0, (new CemeteryScorer(ScoreConfig::enriched()))->score($this->candidate([new Place('Musterstadt')]), null)->score);
        self::assertSame(
            0,
            (new CemeteryScorer(ScoreConfig::enriched()))->score(
                $this->candidate([new Place('Musterstadt')]),
                new Place('Waldfriedhof Andernort'),
            )->score,
        );
    }
}
