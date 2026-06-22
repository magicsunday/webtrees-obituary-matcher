<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Matching\MatchStore;

/**
 * Read-only presenter for the individual suggestion tab. It reads the stored matches for a candidate
 * from the {@see MatchStore}, keeps only the non-terminal rows (Pending or Uncertain) and projects
 * them into view models. The store read is memoised per XREF for the lifetime of the presenter, so a
 * single request that probes both the tab visibility and its contents reads the store only once.
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
     * Constructor.
     *
     * @param MatchStore $store The persistence boundary the suggestions are read from.
     */
    public function __construct(private readonly MatchStore $store)
    {
    }

    /**
     * Returns whether the candidate has any non-terminal suggestion worth showing in the tab.
     *
     * @param string $xref The candidate identifier.
     *
     * @return bool True when at least one non-terminal suggestion exists.
     */
    public function hasContent(string $xref): bool
    {
        return $this->suggestionsFor($xref) !== [];
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
