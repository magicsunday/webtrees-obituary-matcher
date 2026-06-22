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
     * A short candidate place name that IS a whole token of the cemetery is still suppressed by the
     * minimum-token-length guard (removing the guard would let "au" match and score 10).
     */
    #[Test]
    public function shortWholeTokenIsSuppressedByTheLengthGuard(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Au')]),
            new Place('Friedhof Au'),
        );

        self::assertSame(0, $signal->score);
    }

    /**
     * Characterises the tracked #16 limitation: a MULTI-WORD town named in the cemetery does not
     * match, because the candidate place is normalised to a single space-joined needle while the
     * cemetery is matched token-by-token ("bad hersfeld" is never one of ["waldfriedhof", "bad",
     * "hersfeld"]). The 12-char needle clears the 4-char guard, so the zero comes purely from the
     * whole-token limitation — not from the length guard. When #16 lands its unified place matcher,
     * this expectation flips to 10.
     */
    #[Test]
    public function multiWordTownDoesNotMatchYetIssue16(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld')]),
            new Place('Waldfriedhof Bad Hersfeld'),
        );

        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
    }

    /**
     * Characterises the tracked #16 limitation against the REAL adapter place shape: a
     * PersonCandidate place built from gedcomName() is the comma-separated GEDCOM hierarchy
     * "Town, Region, Country", which (commas retained, multi-word) never equals a single cemetery
     * token, so it scores zero even though the cemetery clearly names the town. #16 must split the
     * hierarchy to its most-specific segment before matching.
     */
    #[Test]
    public function commaSeparatedGedcomHierarchyDoesNotMatchYetIssue16(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld, Hessen, Deutschland')]),
            new Place('Waldfriedhof Bad Hersfeld'),
        );

        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
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
