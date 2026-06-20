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
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\PlaceScorer;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the place scorer that rewards matches against residence, birth place and region.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PlaceScorer::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(Gender::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class PlaceScorerTest extends TestCase
{
    /**
     * Builds a person candidate with the given birth place and residences.
     *
     * @param Place       $birthPlace The candidate's birth place
     * @param list<Place> $residences The candidate's residences
     *
     * @return PersonCandidate
     */
    private function candidate(Place $birthPlace, array $residences): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Unknown,
            new PersonName(['Otto'], null, 'Vorbild', null),
            DateRange::unknown(),
            $birthPlace,
            $residences,
            DateRange::unknown(),
        );
    }

    /**
     * A residence match scores the residence weight.
     */
    #[Test]
    public function residenceMatchScoresTwelve(): void
    {
        $candidate = $this->candidate(new Place('Beispielstadt'), [new Place('Musterstadt')]);
        $signal    = (new PlaceScorer(new ScoreConfig()))->score($candidate, new Place('Musterstadt'));
        self::assertSame(12, $signal->score);
        self::assertContains('place matches residence', $signal->reasons);
    }

    /**
     * A birth-place match scores the birth-place weight.
     */
    #[Test]
    public function birthPlaceMatchScoresFive(): void
    {
        $candidate = $this->candidate(new Place('Musterstadt'), []);
        self::assertSame(5, (new PlaceScorer(new ScoreConfig()))->score($candidate, new Place('Musterstadt'))->score);
    }

    /**
     * A null notice place scores zero.
     */
    #[Test]
    public function nullNoticePlaceScoresZero(): void
    {
        $candidate = $this->candidate(new Place('Musterstadt'), [new Place('Musterstadt')]);
        self::assertSame(0, (new PlaceScorer(new ScoreConfig()))->score($candidate, null)->score);
    }

    /**
     * A match within the candidate's region scores the region weight.
     */
    #[Test]
    public function withinRegionScoresEight(): void
    {
        $candidate = $this->candidate(new Place('X'), [new Place('Dorf', null, 'Musterregion')]);
        $signal    = (new PlaceScorer(new ScoreConfig()))->score($candidate, new Place('Musterregion'));
        self::assertSame(8, $signal->score);
    }

    /**
     * An empty/whitespace notice place must not match an empty/whitespace residence,
     * birth place or region: '' === '' would otherwise award place points for nothing.
     */
    #[Test]
    public function emptyNoticePlaceScoresZeroWithoutReason(): void
    {
        $candidate = $this->candidate(
            new Place('   '),
            [new Place('   ', null, '   ')],
        );

        $signal = (new PlaceScorer(new ScoreConfig()))->score($candidate, new Place('   '));

        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
    }
}
