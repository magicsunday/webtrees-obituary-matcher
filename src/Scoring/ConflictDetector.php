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
     * Penalty for two known but non-overlapping birth dates.
     */
    private const int BIRTH_DIFFERENT = 30;

    /**
     * Penalty for two known but non-overlapping death dates.
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

        if ($this->bothKnownAndDisjoint($candidate->birth, $notice->birth)) {
            $reasons[] = new ConflictReason(
                'birth date',
                $this->formatRange($candidate->birth),
                $this->formatRange($notice->birth),
                ConflictSeverity::Hard,
            );
            $penalty += self::BIRTH_DIFFERENT;
        }

        if ($this->bothKnownAndDisjoint($candidate->death, $notice->death)) {
            $reasons[] = new ConflictReason(
                'death date',
                $this->formatRange($candidate->death),
                $this->formatRange($notice->death),
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
     * Checks whether both ranges are known yet do not overlap at all. This subsumes the
     * exact-versus-exact case (two different single days never overlap) while also catching
     * imprecise but non-overlapping contradictions such as the year 1930 against 1940 or
     * ABT 1938 (1936..1940) against 1960.
     *
     * @param DateRange $a The first range.
     * @param DateRange $b The second range.
     *
     * @return bool Whether both are known and disjoint.
     */
    private function bothKnownAndDisjoint(DateRange $a, DateRange $b): bool
    {
        return $a->isKnown()
            && $b->isKnown()
            && !$a->overlaps($b);
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
     * Formats a range as a human-readable value: an ISO YYYY-MM-DD day when the range is an
     * exact single day, otherwise the year (or year-range when the bounds span several years),
     * falling back to the raw source string when no bounds are available.
     *
     * @param DateRange $range The range to format.
     *
     * @return string A readable representation of the range.
     */
    private function formatRange(DateRange $range): string
    {
        if ($range->isExact() && ($range->earliest instanceof DateValue)) {
            return sprintf(
                '%04d-%02d-%02d',
                $range->earliest->year,
                $range->earliest->month ?? 1,
                $range->earliest->day ?? 1,
            );
        }

        if (
            !$range->earliest instanceof DateValue
            || !$range->latest instanceof DateValue
        ) {
            return $range->original ?? '';
        }

        if ($range->earliest->year === $range->latest->year) {
            return sprintf('%04d', $range->earliest->year);
        }

        return sprintf('%04d-%04d', $range->earliest->year, $range->latest->year);
    }
}
