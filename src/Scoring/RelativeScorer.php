<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Scoring;

use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;

use function is_finite;
use function max;
use function min;
use function round;
use function sprintf;

/**
 * Scores obituary relatives against the candidate's family graph. The strongest positive signal:
 * a matched spouse outscores a matched child, every contribution is scaled by the relative's
 * extraction confidence, and each candidate relative is rewarded at most once. This scorer is
 * positive-only — a missing or contradicting relative is never a penalty (the tree may be
 * incomplete and free-text relation guesses are unreliable).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RelativeScorer
{
    /**
     * Points awarded when a notice relative matches a candidate spouse.
     */
    private const int SPOUSE_MATCH = 25;

    /**
     * Points awarded when a notice relative matches a candidate child.
     */
    private const int CHILD_MATCH = 12;

    /**
     * Constructor.
     *
     * @param ScoreConfig $config The scoring configuration.
     */
    public function __construct(private ScoreConfig $config)
    {
    }

    /**
     * Scores the obituary's relatives against the candidate's spouses and children.
     *
     * @param PersonCandidate      $candidate The tree candidate.
     * @param list<NoticeRelative> $relatives The relatives named in the notice.
     *
     * @return SignalScore The relatives signal (0..maxRelatives).
     */
    public function score(PersonCandidate $candidate, array $relatives): SignalScore
    {
        $max     = $this->config->maxRelatives;
        $points  = 0;
        $reasons = [];

        /** @var array<string,true> $matched Candidate relative ids already rewarded. */
        $matched = [];

        foreach ($relatives as $relative) {
            $needle = ObituaryNameParser::parse($relative->name);

            // Clamp an untrusted feeder confidence into [0.0, 1.0]; a non-finite value (NaN/Inf from a
            // faulty feeder) is treated as zero-confidence, since min/max would otherwise map it to 1.0.
            $confidence = is_finite($relative->confidence)
                ? max(0.0, min(1.0, $relative->confidence))
                : 0.0;

            $spouse = $this->findMatch($needle, $candidate->spouses, $matched);

            if ($spouse instanceof RelatedPerson) {
                $matched[$spouse->id] = true;
                $points += (int) round(self::SPOUSE_MATCH * $confidence);
                $reasons[] = sprintf('relative "%s" matches spouse', $relative->name);

                continue;
            }

            $child = $this->findMatch($needle, $candidate->children, $matched);

            if ($child instanceof RelatedPerson) {
                $matched[$child->id] = true;
                $points += (int) round(self::CHILD_MATCH * $confidence);
                $reasons[] = sprintf('relative "%s" matches child', $relative->name);
            }
        }

        if ($points <= 0) {
            return new SignalScore(0, $max, []);
        }

        return new SignalScore(min($points, $max), $max, $reasons);
    }

    /**
     * Returns the first not-yet-matched family member whose name matches the needle, or null.
     *
     * @param PersonName          $needle  The parsed notice-relative name.
     * @param list<RelatedPerson> $family  The candidate's spouses or children.
     * @param array<string,true>  $matched Candidate relative ids already rewarded.
     *
     * @return RelatedPerson|null
     */
    private function findMatch(PersonName $needle, array $family, array $matched): ?RelatedPerson
    {
        foreach ($family as $member) {
            if (isset($matched[$member->id])) {
                continue;
            }

            if ($this->namesMatch($needle, $member->name)) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Matches two names: a surname must be present on BOTH sides and be equal, plus at least one
     * given name must overlap. A notice relative carrying only a given name never matches in 2b.
     *
     * @param PersonName $notice The parsed notice-relative name.
     * @param PersonName $tree   The candidate relative's name.
     *
     * @return bool
     */
    private function namesMatch(PersonName $notice, PersonName $tree): bool
    {
        $noticeSurname = Normalizer::strip($notice->surname);
        $treeSurname   = Normalizer::strip($tree->surname);

        if (
            ($noticeSurname === '')
            || ($treeSurname === '')
            || ($noticeSurname !== $treeSurname)
        ) {
            return false;
        }

        foreach ($notice->givenNames as $noticeGiven) {
            $given = Normalizer::strip($noticeGiven);

            if ($given === '') {
                continue;
            }

            foreach ($tree->givenNames as $treeGiven) {
                if ($given === Normalizer::strip($treeGiven)) {
                    return true;
                }
            }
        }

        return false;
    }
}
