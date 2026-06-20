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
 * The explainable result for one (candidate, obituary) pair.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class MatchExplanation
{
    /**
     * Constructor.
     *
     * @param string                    $personId       Candidate identifier.
     * @param string                    $obituaryUrl    Source URL.
     * @param int                       $total          Clamped 0..100 total score.
     * @param array<string,SignalScore> $signals        The four positive signals, keyed by name.
     * @param ConflictResult            $conflicts      The negative evidence.
     * @param array<string,string>      $extractedFacts Facts to harvest (e.g. deathDate).
     */
    public function __construct(
        public string $personId,
        public string $obituaryUrl,
        public int $total,
        public array $signals,
        public ConflictResult $conflicts,
        public array $extractedFacts,
    ) {
    }

    /**
     * Returns the pair fields (no classification).
     *
     * @return array{
     *     personId: string,
     *     obituaryUrl: string,
     *     score: int,
     *     hardConflict: bool,
     *     signals: array<string, array{score: int, max: int, reasons: list<string>}|array{score: int, reasons: list<array{field: string, treeValue: string, obituaryValue: string, severity: string}>}>,
     *     extractedFacts: array<string, string>
     * }
     */
    public function toArray(): array
    {
        $signals = [];

        foreach ($this->signals as $key => $signal) {
            $signals[$key] = [
                'score'   => $signal->score,
                'max'     => $signal->max,
                'reasons' => $signal->reasons,
            ];
        }

        $conflictReasons = [];

        foreach ($this->conflicts->reasons as $reason) {
            $conflictReasons[] = [
                'field'         => $reason->field,
                'treeValue'     => $reason->treeValue,
                'obituaryValue' => $reason->obituaryValue,
                'severity'      => $reason->severity->value(),
            ];
        }

        $signals['conflicts'] = [
            'score'   => -$this->conflicts->penalty,
            'reasons' => $conflictReasons,
        ];

        return [
            'personId'       => $this->personId,
            'obituaryUrl'    => $this->obituaryUrl,
            'score'          => $this->total,
            'hardConflict'   => $this->conflicts->hasHardConflict(),
            'signals'        => $signals,
            'extractedFacts' => $this->extractedFacts,
        ];
    }
}
