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
use MagicSunday\ObituaryMatcher\Support\PlaceHierarchy;
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
#[UsesClass(PlaceHierarchy::class)]
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
     * A MULTI-WORD town named in the cemetery matches: each word of the candidate place is a
     * whole token, so "bad" and "hersfeld" are both found among the cemetery tokens
     * ["waldfriedhof", "bad", "hersfeld"]. Both words clear the 4-char guard, so the score is the
     * full cemetery-place weight.
     */
    #[Test]
    public function multiWordTownMatches(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld')]),
            new Place('Waldfriedhof Bad Hersfeld'),
        );

        self::assertSame(10, $signal->score);
        self::assertStringContainsString('cemetery', implode(' ', $signal->reasons));
    }

    /**
     * The REAL adapter place shape matches: a PersonCandidate place built from gedcomName() is the
     * comma-separated GEDCOM hierarchy "Town, Region, Country". Each comma-segment is split out and
     * tested token-by-token, so the cemetery naming the town scores the full cemetery-place weight.
     */
    #[Test]
    public function commaSeparatedGedcomHierarchyMatches(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld, Hessen, Deutschland')]),
            new Place('Waldfriedhof Bad Hersfeld'),
        );

        self::assertSame(10, $signal->score);
        self::assertStringContainsString('cemetery', implode(' ', $signal->reasons));
    }

    /**
     * Punctuation attached to a cemetery word (a comma in a GEDCOM-style free-text name) must not
     * fuse onto the token and block a match: "Bad Hersfeld, Waldfriedhof" yields the token
     * "hersfeld" (not "hersfeld,"), so the candidate town "Bad Hersfeld" still scores the full
     * weight. The comma-bearing word is the ONLY matching token here (no other segment coincides),
     * so the assertion fails on the unfixed whitespace-only split.
     */
    #[Test]
    public function cemeteryTokenWithTrailingCommaStillMatches(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld, Hessen, Deutschland')]),
            new Place('Bad Hersfeld, Waldfriedhof'),
        );

        self::assertSame(10, $signal->score);
        self::assertStringContainsString('cemetery', implode(' ', $signal->reasons));
    }

    /**
     * Parentheses around a cemetery's place name must not fuse onto the tokens: "Friedhof (Bad
     * Hersfeld)" yields "bad" and "hersfeld" (not "(bad" / "hersfeld)"), so the candidate town
     * still phrase-matches and scores the full cemetery-place weight.
     */
    #[Test]
    public function cemeteryTokensWithParenthesesStillMatch(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld')]),
            new Place('Friedhof (Bad Hersfeld)'),
        );

        self::assertSame(10, $signal->score);
        self::assertStringContainsString('cemetery', implode(' ', $signal->reasons));
    }

    /**
     * A multi-word town only phrase-matches when EVERY one of its words is a whole cemetery token:
     * a cemetery naming just one of the two words ("Hersfeld" but not "Bad") must not match, so the
     * segment match cannot fire on a partial coincidence.
     */
    #[Test]
    public function multiWordTownNeedsEveryWordAsCemeteryToken(): void
    {
        $signal = (new CemeteryScorer(ScoreConfig::enriched()))->score(
            $this->candidate([new Place('Bad Hersfeld, Hessen, Deutschland')]),
            new Place('Waldfriedhof Hersfeld'),
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
