<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\PhoneticEncoder;

use function array_map;
use function in_array;
use function max;
use function min;

/**
 * Scores how well two names match, by role and with fuzziness. Returns >= 0.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class NameScorer
{
    /**
     * Points awarded for an exact given-name match.
     */
    private const int GIVEN_EXACT = 10;

    /**
     * Points awarded for a given-name variant match.
     */
    private const int GIVEN_VARIANT = 8;

    /**
     * Points awarded when the notice surname matches a married surname.
     */
    private const int SURNAME_MARRIED = 15;

    /**
     * Points awarded when the notice surname matches the birth surname.
     */
    private const int SURNAME_BIRTH = 10;

    /**
     * Points awarded when the notice birth surname matches the candidate birth surname.
     */
    private const int BORN_SURNAME = 20;

    /**
     * Points awarded for a phonetic surname match.
     */
    private const int SURNAME_PHONETIC = 8;

    /**
     * Points awarded when given names and surname match exactly.
     */
    private const int FULL_NAME_EXACT = 30;

    /**
     * Constructor.
     *
     * @param PhoneticEncoder $phonetic The phonetic encoder for fuzzy surname comparison.
     * @param ScoreConfig     $config   The scoring configuration.
     */
    public function __construct(
        private PhoneticEncoder $phonetic,
        private ScoreConfig $config,
    ) {
    }

    /**
     * Scores name agreement between a tree person and an obituary notice name.
     *
     * @param PersonName $candidate The tree person's name.
     * @param PersonName $notice    The notice's parsed name.
     *
     * @return SignalScore The name signal (0..maxName).
     */
    public function score(PersonName $candidate, PersonName $notice): SignalScore
    {
        $score   = 0;
        $reasons = [];

        $givenScore = $this->scoreGivenNames($candidate, $notice, $reasons);
        $score += $givenScore;

        $exactSurnameRole = false;
        $surnameScore     = $this->scoreSurname($candidate, $notice, $reasons, $exactSurnameRole);
        $score += $surnameScore;

        if (
            ($surnameScore > 0)
            && $this->isFullExact($candidate, $notice, $exactSurnameRole)
        ) {
            $score     = max($score, self::FULL_NAME_EXACT + $givenScore);
            $reasons[] = 'full name exact';
        }

        return new SignalScore(min($score, $this->config->maxName), $this->config->maxName, $reasons);
    }

    /**
     * Scores given-name agreement: exact > variant.
     *
     * @param PersonName   $candidate The candidate name.
     * @param PersonName   $notice    The notice name.
     * @param list<string> $reasons   Reason accumulator (by reference).
     *
     * @return int The given-name contribution.
     */
    private function scoreGivenNames(PersonName $candidate, PersonName $notice, array &$reasons): int
    {
        $noticeGiven = array_map(Normalizer::normalize(...), $notice->givenNames);

        foreach ($candidate->givenNames as $given) {
            $normalized = Normalizer::normalize($given);

            // An empty key is not a name: a stripped title ('' === '') must never match an empty
            // entry in the notice given names. Guarding the candidate key closes the exact path.
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, $noticeGiven, true)) {
                $reasons[] = 'given name exact';

                return self::GIVEN_EXACT;
            }
        }

        foreach ($candidate->givenNames as $given) {
            if (Normalizer::normalize($given) === '') {
                continue;
            }

            foreach ($notice->givenNames as $noticeName) {
                if (Normalizer::normalize($noticeName) === '') {
                    continue;
                }

                if (GivenNameVariants::areRelated($given, $noticeName)) {
                    $reasons[] = 'given name variant';

                    return self::GIVEN_VARIANT;
                }
            }
        }

        return 0;
    }

    /**
     * Scores surname-role agreement: birth/married hypothesis cascade.
     *
     * @param PersonName   $candidate        The candidate name.
     * @param PersonName   $notice           The notice name.
     * @param list<string> $reasons          Reason accumulator (by reference).
     * @param bool         $exactSurnameRole Whether an exact (non-phonetic) surname role matched (by reference).
     *
     * @return int The surname-role contribution.
     */
    private function scoreSurname(
        PersonName $candidate,
        PersonName $notice,
        array &$reasons,
        bool &$exactSurnameRole,
    ): int {
        $noticeSurname = Normalizer::normalize($notice->surname);
        $noticeBorn    = ($notice->birthSurname !== null) ? Normalizer::normalize($notice->birthSurname) : null;

        $candidateBirth   = Normalizer::normalize($candidate->birthSurname ?? $candidate->surname);
        $candidateMarried = array_map(Normalizer::normalize(...), $candidate->marriedSurnames);

        // Each branch guards its own operands: an empty key ('' === '') must never award a role.
        // The whole method is no longer short-circuited on an empty notice surname so a
        // "geb. Mueller"-only notice can still recover the high-value born-surname role.
        $best = 0;

        if (
            ($noticeBorn !== null)
            && ($noticeBorn !== '')
            && ($candidateBirth !== '')
            && ($noticeBorn === $candidateBirth)
        ) {
            $reasons[]        = 'birth surname matches';
            $best             = self::BORN_SURNAME;
            $exactSurnameRole = true;
        }

        if (
            ($noticeSurname !== '')
            && in_array($noticeSurname, $candidateMarried, true)
        ) {
            $reasons[]        = 'married name matches';
            $exactSurnameRole = true;

            return $best + self::SURNAME_MARRIED;
        }

        if (
            ($best === 0)
            && ($noticeSurname !== '')
            && ($candidateBirth !== '')
            && ($noticeSurname === $candidateBirth)
        ) {
            $reasons[]        = 'surname matches birth name';
            $exactSurnameRole = true;

            return $best + self::SURNAME_BIRTH;
        }

        if ($best > 0) {
            return $best;
        }

        $noticePhonetic    = $this->phonetic->encode($noticeSurname);
        $candidatePhonetic = $this->phonetic->encode($candidateBirth);

        // A non-codeable token (a bare consonant, digits, punctuation) encodes to '': require a
        // non-empty notice surname and a non-empty key so two unrelated untrusted tokens never
        // collide on an empty phonetic key.
        if (
            ($noticeSurname !== '')
            && ($noticePhonetic !== '')
            && ($noticePhonetic === $candidatePhonetic)
        ) {
            $reasons[] = 'surname phonetic';

            return self::SURNAME_PHONETIC;
        }

        $reasons[] = 'no surname role matched';

        return 0;
    }

    /**
     * Checks whether given names and surname match exactly and the people are maiden-compatible.
     *
     * The full-name-exact bonus is awarded only when the surname agreed via an exact
     * (non-phonetic) role and there is no birth-surname conflict: a married-only match against
     * conflicting maiden names marks different people and must not earn the bonus.
     *
     * @param PersonName $candidate        The candidate name.
     * @param PersonName $notice           The notice name.
     * @param bool       $exactSurnameRole Whether an exact (non-phonetic) surname role matched.
     *
     * @return bool Whether every given name and the surname match exactly without a maiden conflict.
     */
    private function isFullExact(PersonName $candidate, PersonName $notice, bool $exactSurnameRole): bool
    {
        if (!$exactSurnameRole) {
            return false;
        }

        if (!$this->maidenNamesCompatible($candidate, $notice)) {
            return false;
        }

        if (Normalizer::normalize($candidate->surname) !== Normalizer::normalize($notice->surname)) {
            return false;
        }

        $candidateGiven = array_map(Normalizer::normalize(...), $candidate->givenNames);
        $noticeGiven    = array_map(Normalizer::normalize(...), $notice->givenNames);

        return ($candidateGiven !== []) && ($candidateGiven === $noticeGiven);
    }

    /**
     * Checks whether the birth surnames are compatible (not in conflict).
     *
     * When both sides carry a non-empty birth surname, they must be equal once normalized;
     * an absent birth surname on either side is treated as compatible.
     *
     * @param PersonName $candidate The candidate name.
     * @param PersonName $notice    The notice name.
     *
     * @return bool Whether the birth surnames do not contradict each other.
     */
    private function maidenNamesCompatible(PersonName $candidate, PersonName $notice): bool
    {
        if (
            ($candidate->birthSurname === null)
            || ($notice->birthSurname === null)
        ) {
            return true;
        }

        $candidateBirth = Normalizer::normalize($candidate->birthSurname);
        $noticeBirth    = Normalizer::normalize($notice->birthSurname);

        if (
            ($candidateBirth === '')
            || ($noticeBirth === '')
        ) {
            return true;
        }

        return $candidateBirth === $noticeBirth;
    }
}
