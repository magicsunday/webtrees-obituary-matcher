<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\ConflictReason;
use MagicSunday\ObituaryMatcher\Domain\ConflictResult;
use MagicSunday\ObituaryMatcher\Domain\ConflictSeverity;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;

use function min;
use function sprintf;

/**
 * The single source of negative evidence between a candidate and a notice. The
 * penalty is a positive cost that is subtracted from the total match score.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ConflictDetector
{
    /**
     * Penalty for two exact but differing birth dates.
     */
    private const int BIRTH_DIFFERENT = 30;

    /**
     * Penalty for a certain death date in the tree that differs from the notice.
     */
    private const int DEATH_DIFFERENT = 30;

    /**
     * Penalty for a candidate too young at the notice's death date.
     */
    private const int IMPLAUSIBLY_YOUNG = 20;

    /**
     * Placeholder value used when a side carries an unparseable date.
     */
    private const string UNINTERPRETABLE = '(uninterpretable)';

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Detects all conflicts between a candidate and a notice.
     *
     * @param PersonCandidate $candidate The tree candidate.
     * @param ObituaryRecord  $notice    The obituary notice.
     *
     * @return ConflictResult The collected conflicts and the total penalty.
     */
    public function detect(PersonCandidate $candidate, ObituaryRecord $notice): ConflictResult
    {
        $reasons = [];
        $penalty = 0;

        if ($this->bothExactDiffer($candidate->birth, $notice->birth)) {
            $reasons[] = new ConflictReason(
                'birth date',
                $this->formatExact($candidate->birth),
                $this->formatExact($notice->birth),
                ConflictSeverity::Hard,
            );
            $penalty += self::BIRTH_DIFFERENT;
        }

        if ($this->bothExactDiffer($candidate->death, $notice->death)) {
            $reasons[] = new ConflictReason(
                'death date',
                $this->formatExact($candidate->death),
                $this->formatExact($notice->death),
                ConflictSeverity::Hard,
            );
            $penalty += self::DEATH_DIFFERENT;
        }

        if ($this->implausiblyYoung($candidate, $notice)) {
            $reasons[] = new ConflictReason(
                'age',
                (string) $candidate->birth->latest?->year,
                (string) $notice->death->earliest?->year,
                ConflictSeverity::Hard,
            );
            $penalty += self::IMPLAUSIBLY_YOUNG;
        }

        if (
            $candidate->birth->isInvalid()
            || $notice->birth->isInvalid()
        ) {
            $reasons[] = new ConflictReason(
                'birth date',
                $candidate->birth->isInvalid() ? self::UNINTERPRETABLE : '',
                $notice->birth->isInvalid() ? self::UNINTERPRETABLE : '',
                ConflictSeverity::Soft,
            );
        }

        if (
            $candidate->death->isInvalid()
            || $notice->death->isInvalid()
        ) {
            $reasons[] = new ConflictReason(
                'death date',
                $candidate->death->isInvalid() ? self::UNINTERPRETABLE : '',
                $notice->death->isInvalid() ? self::UNINTERPRETABLE : '',
                ConflictSeverity::Soft,
            );
        }

        return new ConflictResult(min($penalty, $this->config->maxPenalty), $reasons);
    }

    /**
     * Checks whether both ranges are exact single days that differ.
     *
     * @param DateRange $a The first range.
     * @param DateRange $b The second range.
     *
     * @return bool Whether both are exact and carry different days.
     */
    private function bothExactDiffer(DateRange $a, DateRange $b): bool
    {
        return $a->isExact()
            && $b->isExact()
            && ($a->earliest instanceof DateValue)
            && ($b->earliest instanceof DateValue)
            && ($a->earliest->comparable() !== $b->earliest->comparable());
    }

    /**
     * Checks whether the candidate would be implausibly young at the notice's death.
     *
     * @param PersonCandidate $candidate The tree candidate.
     * @param ObituaryRecord  $notice    The obituary notice.
     *
     * @return bool Whether the candidate would be under the minimum plausible age.
     */
    private function implausiblyYoung(PersonCandidate $candidate, ObituaryRecord $notice): bool
    {
        if (
            !$candidate->birth->isKnown()
            || !$notice->death->isKnown()
            || !$candidate->birth->latest instanceof DateValue
            || !$notice->death->earliest instanceof DateValue
        ) {
            return false;
        }

        $age = $notice->death->earliest->year - $candidate->birth->latest->year;

        return $age < $this->config->minPlausibleAge;
    }

    /**
     * Formats the earliest day of an exact range as an ISO YYYY-MM-DD string.
     *
     * @param DateRange $range The exact range to format.
     *
     * @return string The formatted day, or an empty string when not exactly known.
     */
    private function formatExact(DateRange $range): string
    {
        if (!$range->earliest instanceof DateValue) {
            return '';
        }

        return sprintf(
            '%04d-%02d-%02d',
            $range->earliest->year,
            $range->earliest->month ?? 1,
            $range->earliest->day ?? 1,
        );
    }
}
