<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Support\NoticeMapper;

use function array_intersect_key;
use function array_map;
use function usort;

/**
 * The Phase-2c vertical slice that turns a validated feeder response into persisted pending
 * suggestions. For every requested person who still has a held candidate, it maps each scraped
 * notice onto a Phase-1 obituary record, scores it with the UNCHANGED engine, classifies the best
 * result against the per-notice set and persists it as a pending match.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class IngestService
{
    /**
     * Constructor.
     *
     * @param ResponseReader $reader     The validating reader for the feeder's response.json.
     * @param MatchEngine    $engine     The unchanged Phase-1 scoring engine.
     * @param Classifier     $classifier The band/ambiguity classifier.
     * @param MatchStore     $store      The persistence boundary for pending suggestions.
     */
    public function __construct(
        private ResponseReader $reader,
        private MatchEngine $engine,
        private Classifier $classifier,
        private MatchStore $store,
    ) {
    }

    /**
     * Reads the job's validated response, scores every notice belonging to a still-held candidate
     * and persists the best result per notice as a pending suggestion.
     *
     * @param string                         $jobId              The job whose response is ingested.
     * @param list<string>                   $requestedPersonIds The person ids that were in the request
     *                                                           (passed straight to the reader as the
     *                                                           strict job-ownership boundary).
     * @param array<string, PersonCandidate> $candidatesById     The candidates the module currently
     *                                                           holds, keyed by person id. A requested
     *                                                           person with no entry here (e.g. now
     *                                                           private or deleted) is skipped, not an
     *                                                           error.
     *
     * @return int The number of pending suggestions stored.
     */
    public function ingest(string $jobId, array $requestedPersonIds, array $candidatesById): int
    {
        // Ownership stays strict: the reader rejects any result for a person not in the request.
        $byPerson = $this->reader->read($jobId, $requestedPersonIds);

        $stored = 0;

        // Iterating the intersection makes the skip-vanished-candidate behaviour structural: a
        // requested person whose candidate is no longer held simply never enters this loop.
        foreach (array_intersect_key($byPerson, $candidatesById) as $personId => $notices) {
            if ($notices === []) {
                continue;
            }

            $candidate = $candidatesById[$personId];

            foreach ($notices as $notice) {
                $this->store->upsertPending($this->classify($candidate, $notice));

                ++$stored;
            }
        }

        return $stored;
    }

    /**
     * Scores one notice against the candidate and wraps the best result into a stored pending match.
     *
     * @param PersonCandidate   $candidate The held candidate for this person.
     * @param DeathNoticeRecord $notice    The scraped notice to score.
     *
     * @return StoredMatch The pending suggestion to persist.
     */
    private function classify(PersonCandidate $candidate, DeathNoticeRecord $notice): StoredMatch
    {
        $obituary = NoticeMapper::toObituaryRecord($notice);

        // The notice is scored against the single held candidate; the best-of-set selection mirrors
        // EngineWorkedExampleTest so a future multi-candidate set is handled identically (and there
        // is no runner-up to carry when exactly one candidate is in the set).
        $results = array_map(
            fn (PersonCandidate $person): MatchExplanation => $this->engine->score($person, $obituary),
            [$candidate],
        );

        usort($results, static fn (MatchExplanation $a, MatchExplanation $b): int => $b->total <=> $a->total);

        $best = $results[0];

        $classification = $this->classifier->classify($best, $results);
        $classified     = new ClassifiedMatch($best, $classification);

        return new StoredMatch(
            $candidate->id,
            $best->obituaryUrl,
            MatchStatus::Pending,
            $classified->toArray(),
        );
    }
}
