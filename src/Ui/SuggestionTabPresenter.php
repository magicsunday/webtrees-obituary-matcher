<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\SearchOutcome;
use MagicSunday\ObituaryMatcher\Matching\CoverageStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;

/**
 * Read-only presenter for the individual suggestion tab. It reads the stored matches for a candidate
 * from the {@see MatchStore}, keeps only the non-terminal rows (Pending or Uncertain) and projects
 * them into view models, and reads the person's per-portal coverage from the {@see CoverageStore} to
 * classify the search outcome (§6.5: a genuine miss vs a portal outage). Both reads are memoised per
 * XREF for the lifetime of the presenter, so a single request that probes visibility and contents
 * reads each store only once.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class SuggestionTabPresenter
{
    /**
     * Per-request memo of the view models, keyed by candidate XREF.
     *
     * @var array<string, list<SuggestionViewModel>>
     */
    private array $memo = [];

    /**
     * Per-request memo of the classified search outcome, keyed by candidate XREF.
     *
     * @var array<string, SearchOutcome>
     */
    private array $outcomeMemo = [];

    /**
     * Constructor.
     *
     * @param MatchStore    $store         The persistence boundary the suggestions are read from.
     * @param CoverageStore $coverageStore The boundary the person's per-portal coverage is read from.
     */
    public function __construct(
        private readonly MatchStore $store,
        private readonly CoverageStore $coverageStore,
    ) {
    }

    /**
     * Returns whether the candidate has anything worth showing in the tab: an open suggestion, or a
     * portal outage on the last search (surfaced so the incomplete result is not read as a clean miss).
     * A genuine miss stays hidden (no empty tab).
     *
     * @param string $xref The candidate identifier.
     *
     * @return bool True when a suggestion or a portal-outage state should be shown.
     */
    public function hasContent(string $xref): bool
    {
        if ($this->suggestionsFor($xref) !== []) {
            return true;
        }

        return $this->searchOutcome($xref) === SearchOutcome::PortalFailed;
    }

    /**
     * The classified search outcome for the candidate, read from the stored per-portal coverage. Lets
     * the view tell a genuine miss (nothing found) from a portal outage (incomplete, offer a retry).
     *
     * @param string $xref The candidate identifier.
     *
     * @return SearchOutcome The classified outcome.
     */
    public function searchOutcome(string $xref): SearchOutcome
    {
        return $this->outcomeMemo[$xref] ??= SearchOutcome::fromCoverage(
            $this->coverageStore->findByPerson($xref),
        );
    }

    /**
     * Returns the non-terminal suggestions for the candidate as view models, reading the store at
     * most once per XREF.
     *
     * @param string $xref The candidate identifier.
     *
     * @return list<SuggestionViewModel> The view-ready suggestions.
     */
    public function suggestionsFor(string $xref): array
    {
        if (isset($this->memo[$xref])) {
            return $this->memo[$xref];
        }

        $visible = [];

        foreach ($this->store->findByPerson($xref) as $row) {
            if (!$row->status->isTerminal()) {
                $visible[] = SuggestionViewModel::fromStoredMatch($row);
            }
        }

        return $this->memo[$xref] = $visible;
    }
}
