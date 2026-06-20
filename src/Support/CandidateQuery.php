<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

/**
 * A single prioritised, plain-text search query derived from a person candidate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CandidateQuery
{
    /**
     * Constructor.
     *
     * @param string $query    The plain-text search query (no quotes, operators or keywords).
     * @param int    $priority The rank (1 = most specific); the lowest number wins on dedup.
     * @param string $dedupKey The normalised full query used to deduplicate equivalent queries.
     */
    public function __construct(
        public string $query,
        public int $priority,
        public string $dedupKey,
    ) {
    }
}
