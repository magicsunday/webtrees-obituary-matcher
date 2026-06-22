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
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
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
final class SuggestionViewModelTest extends TestCase
{
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
            'personId'       => 'I1',
            'obituaryUrl'    => $url,
            'score'          => $score,
            'hardConflict'   => false,
            'signals'        => [],
            'extractedFacts' => $death === null ? [] : ['deathDate' => $death],
            'classification' => $classification,
            'ambiguous'      => false,
            'runnerUp'       => null,
            'review'         => null,
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
}
