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
 * The second-best candidate for a given obituary, used for ambiguity detection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RunnerUp
{
    /**
     * Constructor.
     *
     * @param string      $personId       Candidate identifier.
     * @param int         $score          Total score of this candidate.
     * @param string      $classification Band label of this candidate.
     * @param string      $name           Display name.
     * @param int|null    $birthYear      Birth year, if known.
     * @param string|null $birthPlace     Birth place name, if known.
     */
    public function __construct(
        public string $personId,
        public int $score,
        public string $classification,
        public string $name,
        public ?int $birthYear,
        public ?string $birthPlace,
    ) {
    }

    /**
     * Returns the serialised representation for JSON output.
     *
     * @return array{personId: string, score: int, classification: string, name: string, birthYear: int|null, birthPlace: string|null}
     */
    public function toArray(): array
    {
        return [
            'personId'       => $this->personId,
            'score'          => $this->score,
            'classification' => $this->classification,
            'name'           => $this->name,
            'birthYear'      => $this->birthYear,
            'birthPlace'     => $this->birthPlace,
        ];
    }
}
