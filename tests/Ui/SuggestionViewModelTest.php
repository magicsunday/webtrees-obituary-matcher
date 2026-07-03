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
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Ui\BandKey;
use MagicSunday\ObituaryMatcher\Ui\ObituaryDateFormatter;
use MagicSunday\ObituaryMatcher\Ui\PayloadReader;
use MagicSunday\ObituaryMatcher\Ui\SourceLink;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the suggestion view model: the score/band/status mapping, the German death
 * date formatting and the HTTP-only source link guard that refuses a non-HTTP scheme.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SuggestionViewModel::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(StoredMatchKey::class)]
#[UsesClass(BandKey::class)]
#[UsesClass(Band::class)]
#[UsesClass(ObituaryDateFormatter::class)]
#[UsesClass(SourceLink::class)]
#[UsesClass(PayloadReader::class)]
final class SuggestionViewModelTest extends TestCase
{
    /**
     * Sentinel for the data provider marking a payload key that must be UNSET (absent) rather than set
     * to a value — distinct from any legitimate payload value (including null).
     *
     * @var string
     */
    private const string ABSENT = "\0__absent__\0";

    /**
     * Builds a trusted classified-match payload with overridable fields.
     *
     * @param string      $classification The classification band value.
     * @param int         $score          The match score.
     * @param string|null $death          The extracted death date, or null when absent.
     * @param string      $url            The source notice URL.
     *
     * @return ClassifiedMatchArray
     */
    private function payload(string $classification = 'strong', int $score = 97, ?string $death = '2023-09-04', string $url = 'https://trauer.example/anzeige'): array
    {
        return [
            'personId'        => 'I1',
            'obituaryUrl'     => $url,
            'score'           => $score,
            'hardConflict'    => false,
            'signals'         => [],
            'extractedFacts'  => $death === null ? [] : ['deathDate' => $death],
            'noticeRelatives' => [],
            'classification'  => $classification,
            'ambiguous'       => false,
            'runnerUp'        => null,
            'review'          => null,
        ];
    }

    /**
     * Wraps a payload in a stored match.
     *
     * @param ClassifiedMatchArray $match  The trusted scoring payload.
     * @param MatchStatus          $status The lifecycle status.
     *
     * @return StoredMatch
     */
    private function stored(array $match, MatchStatus $status = MatchStatus::Pending): StoredMatch
    {
        return new StoredMatch('I1', $match['obituaryUrl'], $status, $match);
    }

    #[Test]
    public function mapsScoreBandAndStatus(): void
    {
        $vm = SuggestionViewModel::fromStoredMatch($this->stored($this->payload()));
        self::assertSame(97, $vm->score);
        self::assertSame('strong', $vm->bandKey);
        self::assertSame('pending', $vm->statusKey);
    }

    #[Test]
    public function unknownClassificationFallsBackToNone(): void
    {
        self::assertSame('none', SuggestionViewModel::fromStoredMatch($this->stored($this->payload('<script>')))->bandKey);
    }

    #[Test]
    public function formatsExactDeathDateAsGerman(): void
    {
        self::assertSame('04.09.2023', SuggestionViewModel::fromStoredMatch($this->stored($this->payload(death: '2023-09-04')))->deathDate);
    }

    #[Test]
    public function absentDeathDateIsNull(): void
    {
        self::assertNull(SuggestionViewModel::fromStoredMatch($this->stored($this->payload(death: null)))->deathDate);
    }

    #[Test]
    public function httpUrlYieldsLinkAndHost(): void
    {
        $vm = SuggestionViewModel::fromStoredMatch($this->stored($this->payload()));
        self::assertSame('https://trauer.example/anzeige', $vm->sourceUrl);
        self::assertSame('trauer.example', $vm->sourceHost);
    }

    #[Test]
    public function nonHttpUrlIsRefusedAsLink(): void
    {
        $vm = SuggestionViewModel::fromStoredMatch($this->stored($this->payload(url: 'javascript:alert(1)')));
        self::assertNull($vm->sourceUrl);
        self::assertNull($vm->sourceHost);
    }

    #[Test]
    public function uppercaseHttpSchemeYieldsLink(): void
    {
        $vm = SuggestionViewModel::fromStoredMatch($this->stored($this->payload(url: 'HTTP://trauer.example/x')));
        self::assertSame('HTTP://trauer.example/x', $vm->sourceUrl);
        self::assertSame('trauer.example', $vm->sourceHost);
    }

    /**
     * A malformed-but-array on-disk payload (absent/non-string classification, absent/non-int score,
     * non-array extractedFacts, non-string deathDate, absent ambiguous/hardConflict flags) projects
     * gracefully — band "none", score 0, null death date, false flags — instead of throwing an
     * Undefined-array-key notice or a TypeError that would crash the individual-page obituary tab for
     * every visitor. The narrowing mirrors {@see \MagicSunday\ObituaryMatcher\Ui\WorklistPresenter} and
     * {@see \MagicSunday\ObituaryMatcher\Ui\ReviewViewModel} so all three projections degrade identically.
     *
     * @param mixed       $classification    The classification payload value (or the absence marker).
     * @param mixed       $score             The score payload value (or the absence marker).
     * @param mixed       $extractedFacts    The extractedFacts payload value (or the absence marker).
     * @param string      $expectedBand      The band key the model must carry.
     * @param int         $expectedScore     The score the model must carry.
     * @param string|null $expectedDeathDate The death date the model must carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('malformedPayloadProvider')]
    public function malformedPayloadProjectsGracefully(
        mixed $classification,
        mixed $score,
        mixed $extractedFacts,
        string $expectedBand,
        int $expectedScore,
        ?string $expectedDeathDate,
    ): void {
        $payload = $this->malform($this->payload(), $classification, $score, $extractedFacts);

        $vm = SuggestionViewModel::fromStoredMatch($this->stored($payload));

        self::assertSame($expectedBand, $vm->bandKey);
        self::assertSame($expectedScore, $vm->score);
        self::assertSame($expectedDeathDate, $vm->deathDate);
        self::assertFalse($vm->ambiguous);
        self::assertFalse($vm->hardConflict);
    }

    /**
     * Applies the malformations to a copy of the payload, dropping the flag keys, and re-asserts the
     * trusted shape. The mixed-valued parameter erases the static shape so PHPStan no longer tracks the
     * removed/typewrong keys — the re-asserted shape then models a malformed-but-array on-disk JSON
     * row, exactly as {@see StoredMatch::fromArray()} would reconstruct from disk.
     *
     * @param array<string, mixed> $payload        The original payload, shape-erased.
     * @param mixed                $classification The classification override (or the absence marker).
     * @param mixed                $score          The score override (or the absence marker).
     * @param mixed                $extractedFacts The extractedFacts override (or the absence marker).
     *
     * @return ClassifiedMatchArray The malformed-but-array payload.
     */
    private function malform(array $payload, mixed $classification, mixed $score, mixed $extractedFacts): array
    {
        foreach (['classification' => $classification, 'score' => $score, 'extractedFacts' => $extractedFacts] as $key => $value) {
            if ($value === self::ABSENT) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $value;
            }
        }

        // The flag keys are dropped too, so a missing-key read must collapse to false, not warn.
        unset($payload['ambiguous'], $payload['hardConflict']);

        /** @var ClassifiedMatchArray $payload */
        return $payload;
    }

    /**
     * Malformed-but-array payload shapes that must each project gracefully, paired with the band,
     * score and death date the resulting model must carry.
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: string, 4: int, 5: string|null}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'classification absent'     => [self::ABSENT, 90, ['deathDate' => '2023-09-04'], 'none', 90, '04.09.2023'],
            'classification non-string' => [42, 90, ['deathDate' => '2023-09-04'], 'none', 90, '04.09.2023'],
            'score absent'              => ['strong', self::ABSENT, ['deathDate' => '2023-09-04'], 'strong', 0, '04.09.2023'],
            'score non-int'             => ['strong', 'NaN', ['deathDate' => '2023-09-04'], 'strong', 0, '04.09.2023'],
            'extractedFacts non-array'  => ['strong', 90, 'not-an-array', 'strong', 90, null],
            'extractedFacts absent'     => ['strong', 90, self::ABSENT, 'strong', 90, null],
            'deathDate non-string'      => ['strong', 90, ['deathDate' => 20230904], 'strong', 90, null],
            'all malformed at once'     => [self::ABSENT, self::ABSENT, 99, 'none', 0, null],
        ];
    }

    /**
     * The view model exposes the canonical row key for its source URL. The expected value is the
     * pinned SHA-256 of the identity-normalised URL, not a re-invocation of the production
     * derivation, so a regression in the canonical key derivation flips this literal.
     *
     * @return void
     */
    #[Test]
    public function exposesRowKeyForSourceUrl(): void
    {
        $vm = SuggestionViewModel::fromStoredMatch($this->stored($this->payload(url: 'https://trauer.example/a')));

        self::assertSame('89b60f2d1bdf98d97c9b78ab815b88247d26166e08271c838dcc270f90007d29', $vm->rowKey);
        // Cross-check the pinned literal really is the canonical key for this URL — a guard against
        // the literal silently drifting from the contract it documents.
        self::assertSame(StoredMatchKey::fromUrl('https://trauer.example/a'), $vm->rowKey);
    }
}
