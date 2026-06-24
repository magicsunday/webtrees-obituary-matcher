<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;

use function array_slice;
use function ceil;
use function count;
use function max;
use function min;
use function strcmp;
use function usort;

/**
 * The webtrees-free projection engine for the tree-wide worklist screen: it filters the handler's
 * surviving entries by lifecycle status, sorts them score-descending (tie-broken by personId), pages
 * them at a fixed size and maps each paged entry to a {@see WorklistRowView}. The per-status counts
 * are tallied over EVERY surviving entry (not the filtered page), so the status tabs stay stable as
 * the filter changes. Every value passes through plain/untrusted; the worklist template escapes it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class WorklistPresenter
{
    /**
     * The fixed page size for the worklist.
     */
    public const int WORKLIST_PAGE_SIZE = 50;

    /**
     * Builds the worklist view from the handler's surviving entries.
     *
     * @param list<array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}> $entries      The surviving rows (stale-person rows already skipped).
     * @param string                                                                                                           $statusFilter The requested status filter.
     * @param int                                                                                                              $page         The 1-based requested page.
     *
     * @return WorklistView The filtered, sorted, paginated view.
     */
    public function build(array $entries, string $statusFilter, int $page): WorklistView
    {
        $counts = $this->counts($entries);

        $filter   = $this->normaliseFilter($statusFilter);
        $filtered = $this->filter($entries, $filter);

        usort($filtered, static function (array $a, array $b): int {
            $byScore = self::scoreOf($b['match']) <=> self::scoreOf($a['match']);

            if ($byScore !== 0) {
                return $byScore;
            }

            // Byte-order tie-break (not numeric `<=>`): webtrees permits bare-numeric XREFs, and the
            // module's house invariant sorts candidate XREFs by byte order
            // ({@see \MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository}'s SORT_STRING), so the
            // worklist must match that ordering rather than diverge into numeric comparison.
            return strcmp($a['personId'], $b['personId']);
        });

        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int) ceil($totalFiltered / self::WORKLIST_PAGE_SIZE));
        $clampedPage   = min(max(1, $page), $totalPages);
        $offset        = ($clampedPage - 1) * self::WORKLIST_PAGE_SIZE;

        $rows = [];

        foreach (array_slice($filtered, $offset, self::WORKLIST_PAGE_SIZE) as $entry) {
            $rows[] = $this->toRow($entry);
        }

        return new WorklistView($rows, $counts, $filter, $clampedPage, $totalPages, $totalFiltered);
    }

    /**
     * Maps the requested status filter to its allow-listed key, falling back to "all" for any
     * unknown value.
     *
     * @param string $statusFilter The raw requested status filter.
     *
     * @return string The allow-listed filter key (all/open/confirmed/rejected/uncertain).
     */
    private function normaliseFilter(string $statusFilter): string
    {
        return match ($statusFilter) {
            'open', 'confirmed', 'rejected', 'uncertain' => $statusFilter,
            default                                      => 'all',
        };
    }

    /**
     * Restricts the entries to those matching the (already normalised) filter; "all" passes them
     * through and "open" keeps only Pending rows.
     *
     * @param list<array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}> $entries The surviving rows.
     * @param string                                                                                                           $filter  The normalised filter key.
     *
     * @return list<array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}> The filtered rows.
     */
    private function filter(array $entries, string $filter): array
    {
        if ($filter === 'all') {
            return $entries;
        }

        $status = match ($filter) {
            'open'      => MatchStatus::Pending,
            'confirmed' => MatchStatus::Confirmed,
            'rejected'  => MatchStatus::Rejected,
            default     => MatchStatus::Uncertain,
        };

        $filtered = [];

        foreach ($entries as $entry) {
            if ($entry['match']->status === $status) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }

    /**
     * Tallies the per-status counts over every surviving entry.
     *
     * @param list<array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}> $entries The surviving rows.
     *
     * @return array{total: int, open: int, confirmed: int, rejected: int, uncertain: int} The per-status counts.
     */
    private function counts(array $entries): array
    {
        $counts = ['total' => 0, 'open' => 0, 'confirmed' => 0, 'rejected' => 0, 'uncertain' => 0];

        foreach ($entries as $entry) {
            ++$counts['total'];

            $key = match ($entry['match']->status) {
                MatchStatus::Pending   => 'open',
                MatchStatus::Confirmed => 'confirmed',
                MatchStatus::Rejected  => 'rejected',
                MatchStatus::Uncertain => 'uncertain',
            };

            ++$counts[$key];
        }

        return $counts;
    }

    /**
     * Reads the match score defensively: the payload is the trusted engine shape, but it was
     * reconstructed from untrusted JSON, so the score is read through {@see PayloadReader} (which
     * erases the static shape to mixed) and a missing or non-int value collapses to 0 — the sanctioned
     * mixed-at-boundary read mirroring {@see ReviewViewModel}.
     *
     * @param StoredMatch $match The stored match whose score to read.
     *
     * @return int The score, or 0 when absent or non-int.
     */
    private static function scoreOf(StoredMatch $match): int
    {
        return PayloadReader::asInt(PayloadReader::read($match->match, 'score'), 0);
    }

    /**
     * Projects a single surviving entry to its view row, copying the handler-built personUrl and
     * reviewUrl through verbatim. The classification and the extracted death date are read through
     * {@see PayloadReader} and narrowed defensively — the on-disk payload is reconstructed from
     * untrusted JSON ({@see StoredMatch::fromArray()} only asserts it is an array), so a
     * malformed-but-array row must degrade (band "none", null death date) rather than crash the whole
     * worklist render. This narrows the payload IDENTICALLY to {@see ReviewViewModel}.
     *
     * @param array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null} $entry The surviving row.
     *
     * @return WorklistRowView The projected row.
     */
    private function toRow(array $entry): WorklistRowView
    {
        $match  = $entry['match'];
        $source = SourceLink::fromUrl($match->obituaryUrl);

        $classification = PayloadReader::asString(
            PayloadReader::read($match->match, 'classification'),
            Band::None->value(),
        );

        return new WorklistRowView(
            $entry['personName'],
            $entry['personId'],
            $entry['personUrl'],
            BandKey::normalise($classification),
            self::scoreOf($match),
            ObituaryDateFormatter::toGerman(PayloadReader::nestedString($match->match, 'extractedFacts', 'deathDate')),
            $source->href,
            $source->host,
            $match->status->value,
            $entry['reviewUrl'],
        );
    }
}
