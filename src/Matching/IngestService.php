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
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\EnrichedMatchEngine;
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;

use function array_key_exists;
use function array_map;
use function count;
use function sprintf;
use function usort;

/**
 * The Phase-2 vertical slice that turns a validated finder response into persisted pending
 * suggestions. It is handed the already-validated notices (keyed by person), and for every requested
 * person who still has a held candidate scores each scraped notice DIRECTLY with the enriched engine,
 * classifies the best result against the per-notice set and persists it to the passed store as a
 * pending match. The run is summarised into a typed {@see IngestResult} whose per-metric counts and
 * non-fatal warnings the caller records via {@see \MagicSunday\ObituaryMatcher\Queue\JobTransport::markIngested()}.
 * Reading and validating the transport response is the caller's responsibility (the drain pulls it as a
 * {@see \MagicSunday\ObituaryMatcher\Queue\CompletedJob} from the {@see \MagicSunday\ObituaryMatcher\Queue\JobTransport}),
 * keeping this service transport-agnostic.
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
     * @param EnrichedMatchEngine $engine     The enriched scoring engine that harvests the notice's facts.
     * @param Classifier          $classifier The band/ambiguity classifier.
     */
    public function __construct(
        private EnrichedMatchEngine $engine,
        private Classifier $classifier,
    ) {
    }

    /**
     * Scores every already-validated notice belonging to a still-held candidate and persists the best
     * result per notice as a pending suggestion in the passed store.
     *
     * @param array<string, list<DeathNoticeRecord>> $notices        The already-validated notices to
     *                                                               ingest, keyed by person id (the
     *                                                               strict job-ownership boundary was
     *                                                               enforced by the validator).
     * @param array<string, PersonCandidate>         $candidatesById The candidates the module currently
     *                                                               holds, keyed by person id. A requested
     *                                                               person with a notice but no entry here
     *                                                               (e.g. now private or deleted) is not an
     *                                                               error: it stores nothing and is reported
     *                                                               as a warning.
     * @param MatchStore                             $store          The persistence boundary the suggestions
     *                                                               are written to (passed per call so the
     *                                                               service stays store-agnostic).
     *
     * @return IngestResult The per-metric counts and non-fatal warnings of this run.
     */
    public function ingest(
        array $notices,
        array $candidatesById,
        MatchStore $store,
    ): IngestResult {
        // Ownership was already enforced by the validator that produced these notices: every key here
        // is one of the requested person ids.
        $byPerson = $notices;

        $noticesRead = 0;
        $stored      = 0;

        /** @var list<string> $warnings */
        $warnings = [];

        // Tracks the identity keys persisted this run so two notices whose URLs collapse onto one
        // key (e.g. utm-variant links for the same person) count once, matching the single row that
        // last-write-wins de-dup actually leaves on disk.
        $seenKeys = [];

        foreach ($byPerson as $personId => $personNotices) {
            $noticesRead += count($personNotices);

            if ($personNotices === []) {
                continue;
            }

            // A requested person whose candidate is no longer held this run is not dropped silently:
            // its notices store nothing but surface a non-fatal warning so the drain can record why.
            if (!array_key_exists($personId, $candidatesById)) {
                $warnings[] = sprintf(
                    'Person %s has %d notice(s) but no held candidate this run; skipped.',
                    $personId,
                    count($personNotices),
                );

                continue;
            }

            $candidate = $candidatesById[$personId];

            foreach ($personNotices as $notice) {
                $match = $this->buildPendingMatch($candidate, $notice);

                // The identity key mirrors FileMatchStore's keying so the count tracks distinct
                // rows on disk, not notices iterated.
                $key = $match->personId . '|' . UrlNormalizer::normalizeForIdentity($match->obituaryUrl);

                // Only count a notice that produced an actual NEW row: upsertPending is a silent
                // no-op over a terminal (Confirmed/Rejected) row, and two within-response notices
                // that collapse onto one key write twice but persist one row — counting either case
                // unconditionally would overstate the number persisted. This count is destined for a
                // single named entry of the per-metric counts map that JobTransport::markIngested
                // records (not a bare scalar), so it must stay an exact persisted-row tally.
                if (
                    $store->upsertPending($match)
                    && !isset($seenKeys[$key])
                ) {
                    $seenKeys[$key] = true;
                    ++$stored;
                }
            }
        }

        return new IngestResult($noticesRead, count($candidatesById), $stored, $warnings);
    }

    /**
     * Orchestrates the per-notice pipeline: scores the notice DIRECTLY with the enriched engine (no
     * Phase-1 obituary down-map, so the harvested burial facts survive), sorts the best-of-set
     * result, classifies it against the per-notice set and wraps the outcome into a pending stored
     * match. The name avoids colliding with the injected {@see Classifier::classify()}, which is only
     * one step of this orchestration.
     *
     * @param PersonCandidate   $candidate The held candidate for this person.
     * @param DeathNoticeRecord $notice    The scraped notice to score.
     *
     * @return StoredMatch The pending suggestion to persist.
     */
    private function buildPendingMatch(PersonCandidate $candidate, DeathNoticeRecord $notice): StoredMatch
    {
        // The notice is scored against the single held candidate; the best-of-set selection mirrors
        // EngineWorkedExampleTest so a future multi-candidate set is handled identically (and there
        // is no runner-up to carry when exactly one candidate is in the set).
        $results = array_map(
            fn (PersonCandidate $person): MatchExplanation => $this->engine->score($person, $notice),
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
