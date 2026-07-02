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

/**
 * A serialisable request handed to the external obituary finder as the `POST /jobs` body.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderRequest
{
    /**
     * The MAJOR of the published #56 job-request contract this body targets. A finder rejects an
     * unknown major, so the wire always stamps the contract major — not any internal envelope version.
     */
    private const int JOB_REQUEST_SCHEMA_VERSION = 1;

    /**
     * Constructor.
     *
     * The `$createdAt` and `$treeId` are carried for the local {@see \MagicSunday\ObituaryMatcher\Queue\RestJobTransport}
     * ledger correlation only; they are NOT part of the published contract and never reach the wire.
     *
     * @param string                       $jobId      The caller-assigned job identifier.
     * @param DateTimeImmutable            $createdAt  The moment the request was assembled (ledger-only).
     * @param string                       $locale     The IETF BCP 47 locale tag (e.g. "de-DE").
     * @param list<FinderCandidateRequest> $candidates The per-candidate query bundles.
     * @param int                          $treeId     The numeric webtrees tree identifier (ledger-only).
     */
    public function __construct(
        public string $jobId,
        public DateTimeImmutable $createdAt,
        public string $locale,
        public array $candidates,
        public int $treeId,
    ) {
    }

    /**
     * Serialises the request into the published job-request contract body (see the #56 contract).
     *
     * The internal envelope fields (`createdAt`, `treeId`) are intentionally NOT emitted — they carry
     * no contract meaning and stay local to the transport's ledger.
     *
     * @return array{
     *   schemaVersion: int,
     *   jobId: string,
     *   locale: string,
     *   candidates: list<array{
     *     personId: string,
     *     names: list<array{kind?: string, full?: string, given?: string, surname?: string}>,
     *     queryHints: list<array{query: string, dedupKey: string, priority: int}>,
     *     excludedHosts?: list<string>
     *   }>
     * }
     */
    public function toArray(): array
    {
        $candidates = [];

        foreach ($this->candidates as $candidate) {
            $candidates[] = $candidate->toArray();
        }

        return [
            'schemaVersion' => self::JOB_REQUEST_SCHEMA_VERSION,
            'jobId'         => $this->jobId,
            'locale'        => $this->locale,
            'candidates'    => $candidates,
        ];
    }
}
