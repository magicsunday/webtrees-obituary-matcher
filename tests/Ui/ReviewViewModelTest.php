<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Support\ConfirmDecision;
use MagicSunday\ObituaryMatcher\Support\ConfirmGate;
use MagicSunday\ObituaryMatcher\Support\FamilyNameMatch;
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\FamilyMemberView;
use MagicSunday\ObituaryMatcher\Ui\NoticeRelativeView;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\TreeFamilyMember;
use MagicSunday\ObituaryMatcher\Ui\TreePersonView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Behavioural tests for the review-screen view model: defensive projection of the persisted payload
 * plus the live tree-person DTO.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ReviewViewModel::class)]
#[UsesClass(TreePersonView::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(BandKey::class)]
#[UsesClass(Band::class)]
#[UsesClass(ObituaryDateFormatter::class)]
#[UsesClass(SourceLink::class)]
#[UsesClass(ConfirmDecision::class)]
#[UsesClass(ConfirmGate::class)]
#[UsesClass(GedcomDateConverter::class)]
#[UsesClass(TreeFamilyMember::class)]
#[UsesClass(FamilyMemberView::class)]
#[UsesClass(NoticeRelativeView::class)]
#[UsesClass(FamilyNameMatch::class)]
#[UsesClass(Normalizer::class)]
final class ReviewViewModelTest extends TestCase
{
    /**
     * Builds a stored match with the given payload overrides merged onto a valid base.
     *
     * @param array<string, mixed> $overrides The payload overrides.
     * @param MatchStatus          $status    The lifecycle status.
     * @param string               $url       The source URL.
     *
     * @return StoredMatch The stored match.
     */
    private function match(array $overrides = [], MatchStatus $status = MatchStatus::Pending, string $url = 'https://trauer.example/a'): StoredMatch
    {
        $payload = [
            'personId'       => 'I1',
            'obituaryUrl'    => $url,
            'score'          => 97,
            'hardConflict'   => false,
            'ambiguous'      => false,
            'classification' => 'strong',
            'extractedFacts' => ['deathDate' => '2023-09-04', 'place' => 'Musterstadt'],
            'signals'        => [
                'name'         => ['score' => 45, 'max' => 45, 'reasons' => ['full name exact']],
                'birth'        => ['score' => 30, 'max' => 30, 'reasons' => ['both exact']],
                'place'        => ['score' => 12, 'max' => 15, 'reasons' => ['same place']],
                'plausibility' => ['score' => 10, 'max' => 10, 'reasons' => ['plausible age']],
                'conflicts'    => ['score' => 0, 'reasons' => []],
            ],
            'noticeRelatives' => [],
            'runnerUp'        => null,
            'review'          => null,
        ];

        /** @var ClassifiedMatchArray $merged */
        $merged = [...$payload, ...$overrides];

        return new StoredMatch('I1', $url, $status, $merged);
    }

    /**
     * Builds a tree-person DTO.
     *
     * @return TreePersonView The DTO.
     */
    private function person(): TreePersonView
    {
        return new TreePersonView('I1', 'Maria Mustermann', '14.03.1931', 'Musterstadt', null);
    }

    /**
     * Score, band and status are projected; the four normal signals are kept in order.
     *
     * @return void
     */
    #[Test]
    public function projectsScoreBandStatusAndNormalSignals(): void
    {
        $vm = ReviewViewModel::fromStoredMatch($this->match(), $this->person());

        self::assertSame(97, $vm->score);
        self::assertSame('strong', $vm->bandKey);
        self::assertSame('pending', $vm->statusKey);
        self::assertSame(['name', 'birth', 'place', 'plausibility'], array_column($vm->signals, 'key'));
        self::assertSame(45, $vm->signals[0]['score']);
        self::assertSame(45, $vm->signals[0]['max']);
    }

    /**
     * The conflicts entry is projected separately, not as a normal signal.
     *
     * @return void
     */
    #[Test]
    public function projectsConflictsSeparately(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match([
                'hardConflict' => true,
                'signals'      => [
                    'name'      => ['score' => 45, 'max' => 45, 'reasons' => ['exact']],
                    'conflicts' => [
                        'score'   => -20,
                        'reasons' => [[
                            'field'         => 'deathDate',
                            'treeValue'     => '1990',
                            'obituaryValue' => '2023',
                            'severity'      => 'hard',
                        ]],
                    ],
                ],
            ]),
            $this->person()
        );

        self::assertTrue($vm->hardConflict);
        self::assertNotContains('conflicts', array_column($vm->signals, 'key'));
        self::assertCount(1, $vm->conflicts);
        self::assertSame('deathDate', $vm->conflicts[0]['field']);
        self::assertSame('hard', $vm->conflicts[0]['severity']);
    }

    /**
     * An unknown band collapses to "none"; an unknown signal key is ignored.
     *
     * @return void
     */
    #[Test]
    public function unknownBandToNoneAndUnknownSignalIgnored(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match([
                'classification' => 'super-strong',
                'signals'        => [
                    'name'     => ['score' => 45, 'max' => 45, 'reasons' => []],
                    'nonsense' => ['score' => 5, 'max' => 5, 'reasons' => ['bogus']],
                ],
            ]),
            $this->person()
        );

        self::assertSame('none', $vm->bandKey);
        self::assertSame(['name'], array_column($vm->signals, 'key'));
    }

    /**
     * The enriched signals (relatives / age / cemetery) — active in the enriched ingest profile — are now
     * surfaced in the "why this score" breakdown after the four base signals, each carrying its
     * score/max/reasons, so the reviewer sees the family/age/burial matches the engine scored (#61). Only
     * the keys actually present in the payload project, so the base profile is unaffected.
     *
     * @return void
     */
    #[Test]
    public function projectsTheEnrichedRelativesAgeAndCemeterySignals(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match([
                // Fed OUT of canonical order on purpose: projectSignals derives the row order from the
                // DISPLAYED_SIGNALS constant (base-before-enriched), NOT from payload insertion order, so
                // the ordered assertSame below is a real ordering discriminator rather than a set check.
                'signals' => [
                    'cemetery'  => ['score' => 10, 'max' => 10, 'reasons' => ['cemetery names a known place']],
                    'relatives' => ['score' => 20, 'max' => 35, 'reasons' => ['relative "Max Muster" matches spouse']],
                    'name'      => ['score' => 40, 'max' => 45, 'reasons' => []],
                    'age'       => ['score' => 15, 'max' => 20, 'reasons' => ['age matches the implied birth window']],
                ],
            ]),
            $this->person()
        );

        self::assertSame(['name', 'relatives', 'age', 'cemetery'], array_column($vm->signals, 'key'));

        $relatives = $vm->signals[1];
        self::assertSame('relatives', $relatives['key']);
        self::assertSame(20, $relatives['score']);
        self::assertSame(35, $relatives['max']);
        self::assertSame(['relative "Max Muster" matches spouse'], $relatives['reasons']);
    }

    /**
     * A non-http source yields no link but keeps a display text; an http source links to the host.
     *
     * @return void
     */
    #[Test]
    public function sourceLinkOnlyForHttp(): void
    {
        $http = ReviewViewModel::fromStoredMatch($this->match(), $this->person());
        self::assertSame('https://trauer.example/a', $http->sourceUrl);
        self::assertSame('trauer.example', $http->sourceText);

        $nonHttp = ReviewViewModel::fromStoredMatch($this->match([], MatchStatus::Pending, 'javascript:alert(1)'), $this->person());
        self::assertNull($nonHttp->sourceUrl);
        self::assertSame('javascript:alert(1)', $nonHttp->sourceText);
    }

    /**
     * The death date is formatted DD.MM.YYYY; the tree-person DTO is rendered verbatim, never from
     * the payload (DTO-wins invariant).
     *
     * @return void
     */
    #[Test]
    public function deathDateFormattedAndDtoWinsOverPayload(): void
    {
        $vm = ReviewViewModel::fromStoredMatch($this->match(), $this->person());

        self::assertSame('04.09.2023', $vm->deathDate);
        self::assertSame('Maria Mustermann', $vm->person->name);
        self::assertSame('14.03.1931', $vm->person->birthDate);
        // The DTO's null death date is NOT cross-contaminated by the payload's present deathDate: the
        // tree side comes solely from the DTO, never the persisted obituary payload.
        self::assertNull($vm->person->deathDate);
    }

    /**
     * The promoted death date is surfaced only as the dedicated formatted field and is dropped from
     * the iterated extracted facts, so the review screen never renders it twice (once raw under the
     * untranslated "deathDate" key, once formatted). The other facts (place) survive.
     *
     * @return void
     */
    #[Test]
    public function deathDateIsExcludedFromIteratedFactsButExposedFormatted(): void
    {
        $vm = ReviewViewModel::fromStoredMatch($this->match(), $this->person());

        self::assertSame('04.09.2023', $vm->deathDate);
        self::assertArrayNotHasKey('deathDate', $vm->extractedFacts);
        self::assertSame('Musterstadt', $vm->extractedFacts['place']);
    }

    /**
     * A runner-up summary is exposed only when present, carrying every projected field (name, score,
     * birth year and birth place).
     *
     * @return void
     */
    #[Test]
    public function runnerUpExposedOnlyWhenPresent(): void
    {
        self::assertNull(ReviewViewModel::fromStoredMatch($this->match(), $this->person())->runnerUp);

        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['runnerUp' => [
                'personId'       => 'I0982',
                'score'          => 74,
                'classification' => 'probable',
                'name'           => 'Karl Vorbild',
                'birthYear'      => 1940,
                'birthPlace'     => 'Beispieldorf',
            ]]),
            $this->person()
        );

        self::assertNotNull($vm->runnerUp);
        self::assertSame('Karl Vorbild', $vm->runnerUp['name']);
        self::assertSame(74, $vm->runnerUp['score']);
        self::assertSame(1940, $vm->runnerUp['birthYear']);
        self::assertSame('Beispieldorf', $vm->runnerUp['birthPlace']);
    }

    /**
     * The optional runner-up birth year and birth place narrow to null when their payload values are
     * typewrong (a string year, an int place) — pinning {@see ReviewViewModel::projectRunnerUp()}'s
     * defensive `is_int`/`is_string` branches while the required name/score/classification still pass.
     *
     * @return void
     */
    #[Test]
    public function runnerUpTypewrongOptionalFieldsNarrowToNull(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['runnerUp' => [
                'personId'       => 'I0982',
                'score'          => 74,
                'classification' => 'probable',
                'name'           => 'Karl Vorbild',
                'birthYear'      => '1940',
                'birthPlace'     => 1940,
            ]]),
            $this->person()
        );

        self::assertNotNull($vm->runnerUp);
        self::assertSame('Karl Vorbild', $vm->runnerUp['name']);
        self::assertNull($vm->runnerUp['birthYear']);
        self::assertNull($vm->runnerUp['birthPlace']);
    }

    /**
     * A death date that is not the ISO `YYYY-MM-DD` shape is passed through unchanged.
     *
     * @return void
     */
    #[Test]
    public function nonIsoDeathDatePassesThroughUnchanged(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['extractedFacts' => ['deathDate' => 'um 1923']]),
            $this->person()
        );

        self::assertSame('um 1923', $vm->deathDate);
    }

    /**
     * A normal signal whose score or max is not an int is dropped (typewrong narrowing, spec §6).
     *
     * @return void
     */
    #[Test]
    public function signalWithNonIntScoreOrMaxIsDropped(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match([
                'signals' => [
                    'name'  => ['score' => '45', 'max' => 45, 'reasons' => []],
                    'birth' => ['score' => 30, 'max' => null, 'reasons' => []],
                    'place' => ['score' => 12, 'max' => 15, 'reasons' => []],
                ],
            ]),
            $this->person()
        );

        self::assertSame(['place'], array_column($vm->signals, 'key'));
    }

    /**
     * An HTTP scheme without a parseable host yields a link but falls back to the raw value for the
     * display text.
     *
     * @return void
     */
    #[Test]
    public function httpWithoutHostFallsBackToRawText(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match([], MatchStatus::Pending, 'https:///path-only'),
            $this->person()
        );

        self::assertSame('https:///path-only', $vm->sourceUrl);
        self::assertSame('https:///path-only', $vm->sourceText);
    }

    /**
     * The view model exposes the confirm gate decision (allowed + each disabled reason).
     *
     * @return void
     */
    #[Test]
    public function exposesTheConfirmGate(): void
    {
        // person with no death date, exact extracted date, no conflict → allowed
        $allowed = ReviewViewModel::fromStoredMatch($this->match(), $this->person());
        self::assertTrue($allowed->canConfirm);
        self::assertNull($allowed->confirmDisabledReason);

        // tree already has a death date → disabled with that reason
        $withDate = ReviewViewModel::fromStoredMatch($this->match(), new TreePersonView('I1', 'X', null, null, '01.01.1980'));
        self::assertFalse($withDate->canConfirm);
        self::assertSame('tree_already_has_death_date', $withDate->confirmDisabledReason);

        // imprecise extracted date → disabled
        $imprecise = ReviewViewModel::fromStoredMatch($this->match(['extractedFacts' => ['deathDate' => '2023-09']]), $this->person());
        self::assertFalse($imprecise->canConfirm);
        self::assertSame('no_exact_death_date', $imprecise->confirmDisabledReason);

        // hard conflict wins
        $conflict = ReviewViewModel::fromStoredMatch($this->match(['hardConflict' => true]), $this->person());
        self::assertSame('hard_conflict', $conflict->confirmDisabledReason);
    }

    /**
     * The family-graph panel pairs the tree person's core family against the notice relatives, setting a
     * matched flag on BOTH sides (a tree member is matched when a notice relative loosely corresponds,
     * and vice versa). An unmatched member/relative is neutral, never a conflict (#98).
     *
     * @return void
     */
    #[Test]
    public function projectsFamilyGraphWithMatchedFlagsBothDirections(): void
    {
        $person = new TreePersonView('I1', 'Maria Mustermann', null, null, null, [
            new TreeFamilyMember('Karl Mustermann', 'spouse'),
            new TreeFamilyMember('Otto Mustermann', 'child'),
        ]);

        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['noticeRelatives' => [
                ['name' => 'Karl Mustermann', 'relationGuess' => 'spouse', 'confidence' => 0.9],
                ['name' => 'Erika Beispiel', 'relationGuess' => 'child', 'confidence' => 0.8],
            ]]),
            $person
        );

        // Tree side: the spouse is matched by the notice's Karl; the child has no notice relative.
        self::assertSame(['Karl Mustermann', 'Otto Mustermann'], array_column($vm->familyMembers, 'name'));
        self::assertSame(['spouse', 'child'], array_column($vm->familyMembers, 'relationKey'));
        self::assertSame([true, false], array_column($vm->familyMembers, 'matched'));

        // Notice side: Karl is matched by the tree spouse; Erika corresponds to nobody.
        self::assertSame(['Karl Mustermann', 'Erika Beispiel'], array_column($vm->noticeRelatives, 'name'));
        self::assertSame(['spouse', 'child'], array_column($vm->noticeRelatives, 'relationGuess'));
        self::assertSame([true, false], array_column($vm->noticeRelatives, 'matched'));
        self::assertSame([false, false], array_column($vm->noticeRelatives, 'uncertain'));
    }

    /**
     * A notice relative whose extraction confidence is below the display threshold is flagged uncertain;
     * one at or above the threshold is not (#98).
     *
     * @return void
     */
    #[Test]
    public function marksLowConfidenceNoticeRelativeAsUncertain(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['noticeRelatives' => [
                ['name' => 'Anna Beispiel', 'relationGuess' => 'child', 'confidence' => 0.49],
                ['name' => 'Bert Beispiel', 'relationGuess' => 'child', 'confidence' => 0.5],
            ]]),
            $this->person()
        );

        self::assertSame([true, false], array_column($vm->noticeRelatives, 'uncertain'));
    }

    /**
     * The notice relatives come from the untrusted payload, so each entry is narrowed defensively: a
     * non-array entry or one with a non-string / empty name is dropped, a typewrong relation guess
     * collapses to the empty string, and a typewrong confidence collapses to zero (thus uncertain). A
     * non-array `noticeRelatives` value yields an empty list (#98).
     *
     * @return void
     */
    #[Test]
    public function narrowsMalformedNoticeRelativesDefensively(): void
    {
        $vm = ReviewViewModel::fromStoredMatch(
            $this->match(['noticeRelatives' => [
                'not-an-array',
                ['name' => 123, 'relationGuess' => 'spouse', 'confidence' => 0.9],
                ['name' => '', 'relationGuess' => 'child', 'confidence' => 0.9],
                ['name' => 'Lone Name', 'relationGuess' => 5, 'confidence' => 'x'],
            ]]),
            $this->person()
        );

        self::assertSame(['Lone Name'], array_column($vm->noticeRelatives, 'name'));
        self::assertSame([''], array_column($vm->noticeRelatives, 'relationGuess'));
        self::assertSame([true], array_column($vm->noticeRelatives, 'uncertain'));

        $notArray = ReviewViewModel::fromStoredMatch(
            $this->match(['noticeRelatives' => 'nope']),
            $this->person()
        );

        self::assertSame([], $notArray->noticeRelatives);
    }
}
