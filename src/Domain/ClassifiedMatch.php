<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * A pair result together with its set-dependent classification.
 *
 * @phpstan-type ClassifiedMatchArray = array{
 *     personId: string,
 *     obituaryUrl: string,
 *     score: int,
 *     hardConflict: bool,
 *     signals: array<string, array{score: int, max: int, reasons: list<string>}|array{score: int, reasons: list<array{field: string, treeValue: string, obituaryValue: string, severity: string}>}>,
 *     extractedFacts: array<string, string>,
 *     noticeRelatives: list<array{name: string, relationGuess: string, confidence: float}>,
 *     classification: string,
 *     ambiguous: bool,
 *     runnerUp: array{personId: string, score: int, classification: string, name: string, birthYear: int|null, birthPlace: string|null}|null,
 *     review: string|null
 * }
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ClassifiedMatch
{
    /**
     * Constructor.
     *
     * @param MatchExplanation $match          The pair result.
     * @param Classification   $classification The band and ambiguity.
     * @param RunnerUp|null    $runnerUp       The second-best candidate, if any.
     * @param string|null      $review         Reserved for the later review workflow.
     */
    public function __construct(
        public MatchExplanation $match,
        public Classification $classification,
        public ?RunnerUp $runnerUp = null,
        public ?string $review = null,
    ) {
    }

    /**
     * Returns the full review JSON shape.
     *
     * @return ClassifiedMatchArray
     */
    public function toArray(): array
    {
        $array = $this->match->toArray();

        $array['classification'] = $this->classification->band->value();
        $array['ambiguous']      = $this->classification->ambiguous;
        $array['runnerUp']       = $this->runnerUp?->toArray();
        $array['review']         = $this->review;

        return $array;
    }

    /**
     * Returns the zero-value classified-match shape used as a synthesised payload when no scored
     * result exists for a key (for example, rejecting a notice that was never ingested). Keeping the
     * empty literal next to its canonical {@see ClassifiedMatchArray} definition means a future
     * engine field is added in exactly one place.
     *
     * @param string $personId    The candidate identifier the empty payload belongs to.
     * @param string $obituaryUrl The source notice URL the empty payload belongs to.
     *
     * @return ClassifiedMatchArray The zero-value payload shape.
     */
    public static function emptyArray(string $personId, string $obituaryUrl): array
    {
        return [
            'personId'        => $personId,
            'obituaryUrl'     => $obituaryUrl,
            'score'           => 0,
            'hardConflict'    => false,
            'signals'         => [],
            'extractedFacts'  => [],
            'noticeRelatives' => [],
            'classification'  => '',
            'ambiguous'       => false,
            'runnerUp'        => null,
            'review'          => null,
        ];
    }
}
