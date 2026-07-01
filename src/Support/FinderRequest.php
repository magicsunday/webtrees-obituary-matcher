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
use DateTimeInterface;

/**
 * A serialisable, schema-versioned request handed to the external obituary finder.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderRequest
{
    /**
     * Constructor.
     *
     * @param int                          $schemaVersion The request payload's schema version.
     * @param string                       $jobId         The caller-assigned job identifier.
     * @param DateTimeImmutable            $createdAt     The moment the request was assembled.
     * @param string                       $locale        The IETF BCP 47 locale tag (e.g. "de-DE").
     * @param list<FinderCandidateRequest> $candidates    The per-candidate query bundles.
     * @param int                          $treeId        The numeric webtrees tree identifier the request belongs to.
     */
    public function __construct(
        public int $schemaVersion,
        public string $jobId,
        public DateTimeImmutable $createdAt,
        public string $locale,
        public array $candidates,
        public int $treeId,
    ) {
    }

    /**
     * Serialises the request into a plain, JSON-ready array.
     *
     * @return array{
     *   schemaVersion: int,
     *   jobId: string,
     *   createdAt: string,
     *   locale: string,
     *   candidates: list<array{
     *     personId: string,
     *     queries: list<array{query: string, priority: int, dedupKey: string}>,
     *     excludedHosts: list<string>
     *   }>,
     *   treeId: int
     * }
     */
    public function toArray(): array
    {
        $candidates = [];

        foreach ($this->candidates as $candidate) {
            $candidates[] = $candidate->toArray();
        }

        return [
            'schemaVersion' => $this->schemaVersion,
            'jobId'         => $this->jobId,
            'createdAt'     => $this->createdAt->format(DateTimeInterface::ATOM),
            'locale'        => $this->locale,
            'candidates'    => $candidates,
            'treeId'        => $this->treeId,
        ];
    }
}
