<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;

/**
 * Assembles a schema-versioned finder request from tree person candidates.
 *
 * The factory is pure: it derives each candidate's prioritised queries via the injected
 * {@see QueryGenerator} and carries no I/O or webtrees coupling.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderRequestFactory
{
    /**
     * Constructor.
     *
     * @param QueryGenerator $queryGenerator The pure query generator used per candidate.
     */
    public function __construct(
        private QueryGenerator $queryGenerator,
    ) {
    }

    /**
     * Builds a finder request bundling each candidate's prioritised, deduplicated queries and the
     * portals it already has an open match on.
     *
     * @param string                      $jobId                   The caller-assigned job identifier.
     * @param DateTimeImmutable           $createdAt               The moment the request is assembled.
     * @param string                      $locale                  The IETF BCP 47 locale tag (e.g. "de-DE").
     * @param list<PersonCandidate>       $candidates              The candidates to derive queries from.
     * @param int                         $treeId                  The numeric webtrees tree identifier the request belongs to.
     * @param array<string, list<string>> $excludedHostsByPersonId Per-personId canonical excluded hosts.
     *
     * @return FinderRequest The assembled, serialisable request.
     */
    public function build(
        string $jobId,
        DateTimeImmutable $createdAt,
        string $locale,
        array $candidates,
        int $treeId,
        array $excludedHostsByPersonId = [],
    ): FinderRequest {
        $candidateRequests = [];

        foreach ($candidates as $candidate) {
            $candidateRequests[] = new FinderCandidateRequest(
                $candidate->id,
                $candidate->name,
                $this->queryGenerator->generate($candidate),
                $excludedHostsByPersonId[$candidate->id] ?? [],
            );
        }

        return new FinderRequest(
            $jobId,
            $createdAt,
            $locale,
            $candidateRequests,
            $treeId,
        );
    }
}
