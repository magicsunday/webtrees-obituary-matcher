<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

/**
 * The outcome of one {@see IngestService::ingest()} run: the per-metric counts the queue records
 * via {@see \MagicSunday\ObituaryMatcher\Queue\QueueClient::markIngested()} plus the non-fatal
 * warnings collected while draining. A notice belonging to a person who no longer has a held
 * candidate is not an error; it is reported as a warning rather than dropped silently.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class IngestResult
{
    /**
     * Constructor.
     *
     * @param int          $noticesRead     The number of notices read from the validated response.
     * @param int          $candidatesFound The number of held candidates the run was given.
     * @param int          $matchesStored   The number of distinct pending suggestions persisted.
     * @param list<string> $warnings        The non-fatal warnings collected while draining.
     */
    public function __construct(
        public int $noticesRead,
        public int $candidatesFound,
        public int $matchesStored,
        public array $warnings,
    ) {
    }
}
