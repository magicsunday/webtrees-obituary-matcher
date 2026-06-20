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
     * @return array{
     *     personId: string,
     *     obituaryUrl: string,
     *     score: int,
     *     hardConflict: bool,
     *     signals: array<string, array{score: int, max: int, reasons: list<string>}|array{score: int, reasons: list<array{field: string, treeValue: string, obituaryValue: string, severity: string}>}>,
     *     extractedFacts: array<string, string>,
     *     classification: string,
     *     ambiguous: bool,
     *     runnerUp: array{personId: string, score: int, classification: string, name: string, birthYear: int|null, birthPlace: string|null}|null,
     *     review: string|null
     * }
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
}
