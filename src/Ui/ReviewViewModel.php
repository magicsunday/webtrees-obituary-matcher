<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Support\ConfirmGate;

use function is_array;
use function is_int;
use function is_string;

/**
 * A read-only, view-ready projection of a {@see StoredMatch} for the review screen. It exposes the
 * trusted score, band, status and flags, the per-signal contributions (allow-listed by key), the
 * conflicts block, the extracted facts and an optional runner-up summary, and pairs them with the
 * live {@see TreePersonView}. It never scores and never reads the tree: the tree side comes solely
 * from the passed-in DTO, the obituary side solely from the persisted payload.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ReviewViewModel
{
    /**
     * The normal positive-signal keys rendered in the "why this score" breakdown, in display order.
     *
     * @var list<string>
     */
    private const array NORMAL_SIGNALS = ['name', 'birth', 'place', 'plausibility'];

    /**
     * Constructor.
     *
     * @param TreePersonView                                                                                             $person                The live tree-person projection.
     * @param int                                                                                                        $score                 The match score.
     * @param string                                                                                                     $bandKey               The normalised classification band.
     * @param string                                                                                                     $statusKey             The lifecycle status backing value.
     * @param bool                                                                                                       $ambiguous             Whether the match is ambiguous.
     * @param bool                                                                                                       $hardConflict          Whether a hard conflict is present.
     * @param string|null                                                                                                $deathDate             The German-formatted extracted death date, or null.
     * @param string|null                                                                                                $sourceUrl             The HTTP(S) source URL, or null when refused.
     * @param string                                                                                                     $sourceText            The host for an HTTP(S) URL, else the raw value.
     * @param array<string, string>                                                                                      $extractedFacts        The extracted facts, key => value.
     * @param list<array{key: string, score: int, max: int, reasons: list<string>}>                                      $signals               The normal positive signals.
     * @param list<array{field: string, treeValue: string, obituaryValue: string, severity: string}>                     $conflicts             The conflict reasons.
     * @param array{name: string, birthYear: int|null, birthPlace: string|null, score: int, classification: string}|null $runnerUp              The runner-up summary, or null.
     * @param bool                                                                                                       $canConfirm            Whether the match may be confirmed and written back.
     * @param string|null                                                                                                $confirmDisabledReason The highest-priority reason key when confirm is disabled, else null.
     */
    public function __construct(
        public TreePersonView $person,
        public int $score,
        public string $bandKey,
        public string $statusKey,
        public bool $ambiguous,
        public bool $hardConflict,
        public ?string $deathDate,
        public ?string $sourceUrl,
        public string $sourceText,
        public array $extractedFacts,
        public array $signals,
        public array $conflicts,
        public ?array $runnerUp,
        public bool $canConfirm,
        public ?string $confirmDisabledReason,
    ) {
    }

    /**
     * Projects a stored match and the live tree-person DTO into a review-ready model.
     *
     * @param StoredMatch    $match  The stored match to project.
     * @param TreePersonView $person The live tree-person projection.
     *
     * @return self The view-ready projection.
     */
    public static function fromStoredMatch(StoredMatch $match, TreePersonView $person): self
    {
        // The payload is the trusted engine shape, but it was reconstructed from untrusted JSON, so
        // every key is read through read() — which deliberately erases the static shape to mixed — and
        // narrowed defensively here, so a malformed-but-array payload raises no notice (spec §6). This
        // is the sanctioned mixed-at-boundary use (see the plan's Global Constraints).
        $payload = $match->match;

        $score          = PayloadReader::asInt(PayloadReader::read($payload, 'score'), 0);
        $classification = PayloadReader::asString(PayloadReader::read($payload, 'classification'), 'none');
        $band           = BandKey::normalise($classification);
        $ambiguous      = PayloadReader::read($payload, 'ambiguous') === true;
        $hardConflict   = PayloadReader::read($payload, 'hardConflict') === true;
        $extractedFacts = self::projectFacts(PayloadReader::read($payload, 'extractedFacts'));
        $signals        = PayloadReader::read($payload, 'signals');

        $url    = $match->obituaryUrl;
        $source = SourceLink::fromUrl($url);

        // The death date is surfaced as the dedicated, German-formatted $deathDate field, so it must
        // not also appear in the iterated facts — otherwise the review screen renders it twice (once
        // raw under an untranslated "deathDate" key, once formatted). Read it out, then drop the key
        // from the facts map the view iterates.
        $deathRaw = $extractedFacts['deathDate'] ?? null;

        // The confirm gate is evaluated on the raw ISO death date (before it is dropped from the
        // iterated facts) and the DTO's tree death date, so the review screen's Confirm button and the
        // handler's pre-write re-check share one decision (see ConfirmGate).
        $confirm = ConfirmGate::evaluate($hardConflict, $person->deathDate !== null, $deathRaw);

        unset($extractedFacts['deathDate']);

        return new self(
            $person,
            $score,
            $band,
            $match->status->value,
            $ambiguous,
            $hardConflict,
            ObituaryDateFormatter::toGerman($deathRaw),
            $source->href,
            $source->host ?? $url,
            $extractedFacts,
            self::projectSignals($signals),
            self::projectConflicts(is_array($signals) ? PayloadReader::read($signals, 'conflicts') : null),
            self::projectRunnerUp(PayloadReader::read($payload, 'runnerUp')),
            $confirm->canConfirm,
            $confirm->reasonKey,
        );
    }

    /**
     * Narrows the extracted facts to a string-keyed string map, dropping any non-string entry.
     *
     * @param mixed $facts The raw extracted facts, if any.
     *
     * @return array<string, string> The narrowed facts.
     */
    private static function projectFacts(mixed $facts): array
    {
        if (!is_array($facts)) {
            return [];
        }

        $projected = [];

        foreach ($facts as $key => $value) {
            if (
                is_string($key)
                && is_string($value)
            ) {
                $projected[$key] = $value;
            }
        }

        return $projected;
    }

    /**
     * Projects the allow-listed normal signals, ignoring unknown keys and the conflicts entry, and
     * narrowing every field so a typewrong payload cannot leak through.
     *
     * @param mixed $signals The raw signals map, if any.
     *
     * @return list<array{key: string, score: int, max: int, reasons: list<string>}> The normal signals.
     */
    private static function projectSignals(mixed $signals): array
    {
        if (!is_array($signals)) {
            return [];
        }

        $projected = [];

        foreach (self::NORMAL_SIGNALS as $key) {
            $signal = $signals[$key] ?? null;

            if (!is_array($signal)) {
                continue;
            }

            if (!is_int($signal['score'] ?? null)) {
                continue;
            }

            if (!is_int($signal['max'] ?? null)) {
                continue;
            }

            $reasons = [];

            if (is_array($signal['reasons'] ?? null)) {
                foreach ($signal['reasons'] as $reason) {
                    if (is_string($reason)) {
                        $reasons[] = $reason;
                    }
                }
            }

            $projected[] = [
                'key'     => $key,
                'score'   => $signal['score'],
                'max'     => $signal['max'],
                'reasons' => $reasons,
            ];
        }

        return $projected;
    }

    /**
     * Projects the conflict reasons from the dedicated conflicts entry.
     *
     * @param mixed $conflicts The raw conflicts entry, if any.
     *
     * @return list<array{field: string, treeValue: string, obituaryValue: string, severity: string}> The conflicts.
     */
    private static function projectConflicts(mixed $conflicts): array
    {
        if (
            !is_array($conflicts)
            || !is_array($conflicts['reasons'] ?? null)
        ) {
            return [];
        }

        $projected = [];

        foreach ($conflicts['reasons'] as $reason) {
            if (!is_array($reason)) {
                continue;
            }

            $projected[] = [
                'field'         => is_string($reason['field'] ?? null) ? $reason['field'] : '',
                'treeValue'     => is_string($reason['treeValue'] ?? null) ? $reason['treeValue'] : '',
                'obituaryValue' => is_string($reason['obituaryValue'] ?? null) ? $reason['obituaryValue'] : '',
                'severity'      => is_string($reason['severity'] ?? null) ? $reason['severity'] : '',
            ];
        }

        return $projected;
    }

    /**
     * Projects the runner-up summary, or null when absent or typewrong on its required fields.
     *
     * @param mixed $runnerUp The raw runner-up entry, if any.
     *
     * @return array{name: string, birthYear: int|null, birthPlace: string|null, score: int, classification: string}|null The runner-up summary.
     */
    private static function projectRunnerUp(mixed $runnerUp): ?array
    {
        if (
            !is_array($runnerUp)
            || !is_string($runnerUp['name'] ?? null)
            || !is_int($runnerUp['score'] ?? null)
            || !is_string($runnerUp['classification'] ?? null)
        ) {
            return null;
        }

        $birthYear  = $runnerUp['birthYear'] ?? null;
        $birthPlace = $runnerUp['birthPlace'] ?? null;

        return [
            'name'           => $runnerUp['name'],
            'birthYear'      => is_int($birthYear) ? $birthYear : null,
            'birthPlace'     => is_string($birthPlace) ? $birthPlace : null,
            'score'          => $runnerUp['score'],
            'classification' => $runnerUp['classification'],
        ];
    }
}
