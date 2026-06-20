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
 * Assembles a schema-versioned feeder request from tree person candidates.
 *
 * The factory is pure: it derives each candidate's prioritised queries via the injected
 * {@see QueryGenerator} and carries no I/O or webtrees coupling.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FeederRequestFactory
{
    /**
     * The schema version stamped onto every request this factory builds.
     */
    private const int SCHEMA_VERSION = 1;

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
     * Builds a feeder request bundling each candidate's prioritised, deduplicated queries.
     *
     * @param string                $jobId      The caller-assigned job identifier.
     * @param DateTimeImmutable     $createdAt  The moment the request is assembled.
     * @param string                $locale     The IETF BCP 47 locale tag (e.g. "de-DE").
     * @param list<PersonCandidate> $candidates The candidates to derive queries from.
     *
     * @return FeederRequest The assembled, serialisable request.
     */
    public function build(
        string $jobId,
        DateTimeImmutable $createdAt,
        string $locale,
        array $candidates,
    ): FeederRequest {
        $candidateRequests = [];

        foreach ($candidates as $candidate) {
            $candidateRequests[] = new FeederCandidateRequest(
                $candidate->id,
                $this->queryGenerator->generate($candidate),
            );
        }

        return new FeederRequest(
            self::SCHEMA_VERSION,
            $jobId,
            $createdAt,
            $locale,
            $candidateRequests,
        );
    }
}
