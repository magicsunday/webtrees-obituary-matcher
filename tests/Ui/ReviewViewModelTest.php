<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Ui\ReviewViewModel;
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
            'runnerUp' => null,
            'review'   => null,
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
        return new TreePersonView('I1', 'Maria Mustermann', '14.03.1931', 'Musterstadt', null, 'F');
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
                    'cemetery' => ['score' => 5, 'max' => 5, 'reasons' => ['enriched']],
                ],
            ]),
            $this->person()
        );

        self::assertSame('none', $vm->bandKey);
        self::assertSame(['name'], array_column($vm->signals, 'key'));
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
    }

    /**
     * A runner-up summary is exposed only when present.
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
}
