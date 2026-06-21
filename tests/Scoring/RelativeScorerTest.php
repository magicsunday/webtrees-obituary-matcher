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
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\RelativeScorer;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the relative scorer that matches obituary relatives against the candidate's family graph.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RelativeScorer::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(Gender::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(RelatedPerson::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
#[UsesClass(ObituaryNameParser::class)]
#[UsesClass(Normalizer::class)]
final class RelativeScorerTest extends TestCase
{
    /**
     * Builds a candidate with the given spouses and children and no other usable data.
     *
     * @param list<RelatedPerson> $spouses  The candidate's spouses.
     * @param list<RelatedPerson> $children The candidate's children.
     *
     * @return PersonCandidate
     */
    private function candidate(array $spouses, array $children): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::unknown(),
            null,
            [],
            DateRange::unknown(),
            $spouses,
            $children,
        );
    }

    /**
     * Builds a related person (spouse/child) with a structured name.
     *
     * @param string $id      The relative's identifier.
     * @param string $given   The relative's given name.
     * @param string $surname The relative's surname.
     *
     * @return RelatedPerson
     */
    private function relative(string $id, string $given, string $surname): RelatedPerson
    {
        return new RelatedPerson($id, new PersonName([$given], null, $surname, null));
    }

    /**
     * A spouse name match scores the full spouse weight at confidence 1.0.
     */
    #[Test]
    public function spouseMatchScoresTwentyFive(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Karl Mustermann', 'spouse', 1.0)],
        );

        self::assertSame(25, $signal->score);
        self::assertStringContainsString('spouse', implode(' ', $signal->reasons));
    }

    /**
     * A child name match scores the child weight.
     */
    #[Test]
    public function childMatchScoresTwelve(): void
    {
        $candidate = $this->candidate([], [$this->relative('C1', 'Anna', 'Mustermann')]);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Anna Mustermann', 'child', 1.0)],
        );

        self::assertSame(12, $signal->score);
    }

    /**
     * A low-confidence relative scales the points down (round(25 * 0.5) = 13).
     */
    #[Test]
    public function lowConfidenceScalesDown(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Karl Mustermann', 'spouse', 0.5)],
        );

        self::assertSame(13, $signal->score);
    }

    /**
     * A confidence above 1.0 from a faulty feeder is clamped to 1.0 (full points, never more).
     */
    #[Test]
    public function confidenceAboveOneIsClampedToOne(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Karl Mustermann', 'spouse', 1.5)],
        );

        self::assertSame(25, $signal->score);
    }

    /**
     * A negative confidence is clamped to 0.0 (no points, never negative).
     */
    #[Test]
    public function negativeConfidenceIsClampedToZero(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Karl Mustermann', 'spouse', -1.0)],
        );

        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
    }

    /**
     * The same candidate relative named twice in the notice is scored only once (dedup by id).
     */
    #[Test]
    public function repeatedRelativeIsScoredOnce(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [
                new NoticeRelative('Karl Mustermann', 'spouse', 1.0),
                new NoticeRelative('Karl Mustermann', 'spouse', 1.0),
            ],
        );

        self::assertSame(25, $signal->score);
    }

    /**
     * A notice relative with only a given name (no surname) is not matched in 2b.
     */
    #[Test]
    public function givenNameOnlyRelativeDoesNotMatch(): void
    {
        $candidate = $this->candidate([$this->relative('S1', 'Karl', 'Mustermann')], []);
        $signal    = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [new NoticeRelative('Karl', 'spouse', 1.0)],
        );

        self::assertSame(0, $signal->score);
        self::assertSame([], $signal->reasons);
    }

    /**
     * No family graph and no relatives both score zero.
     */
    #[Test]
    public function emptyGraphOrNoRelativesScoresZero(): void
    {
        self::assertSame(0, (new RelativeScorer(ScoreConfig::enriched()))->score($this->candidate([], []), [])->score);
        self::assertSame(
            0,
            (new RelativeScorer(ScoreConfig::enriched()))->score(
                $this->candidate([], []),
                [new NoticeRelative('Karl Mustermann', 'spouse', 1.0)],
            )->score,
        );
    }

    /**
     * Several matches are summed and clamped to the relatives cap (35): spouse 25 + two children
     * 12 + 12 = 49, clamped to 35.
     */
    #[Test]
    public function pointsAreClampedToTheCap(): void
    {
        $candidate = $this->candidate(
            [$this->relative('S1', 'Karl', 'Mustermann')],
            [$this->relative('C1', 'Anna', 'Mustermann'), $this->relative('C2', 'Max', 'Mustermann')],
        );
        $signal = (new RelativeScorer(ScoreConfig::enriched()))->score(
            $candidate,
            [
                new NoticeRelative('Karl Mustermann', 'spouse', 1.0),
                new NoticeRelative('Anna Mustermann', 'child', 1.0),
                new NoticeRelative('Max Mustermann', 'child', 1.0),
            ],
        );

        self::assertSame(35, $signal->score);
        self::assertSame(35, $signal->max);
    }
}
