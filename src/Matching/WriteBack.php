<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use InvalidArgumentException;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_string;

/**
 * The persistence record of a confirm's GEDCOM write-back, stored on a {@see StoredMatch} when it
 * is confirmed, so a later Revert can undo exactly what was written. 2d-3a populates `deatFactId`,
 * `sourceXref` and `sourceCreated`; `buriFactId` (2d-3b) and `citationIds` (standalone citations)
 * are reserved and stay `null`/`[]` so the persisted shape does not change between slices.
 *
 * @phpstan-type WriteBackArray array{deatFactId: string, buriFactId: string|null, sourceXref: string, sourceCreated: bool, citationIds: list<string>}
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class WriteBack
{
    /**
     * Constructor.
     *
     * @param string       $deatFactId    The fact id of the written DEAT fact.
     * @param string       $sourceXref    The portal source record xref the citation points at.
     * @param bool         $sourceCreated Whether this confirm newly created the portal source.
     * @param string|null  $buriFactId    Reserved for 2d-3b (BURI); null in 2d-3a.
     * @param list<string> $citationIds   Reserved; empty in 2d-3a (the citation is inline in DEAT).
     */
    public function __construct(
        public string $deatFactId,
        public string $sourceXref,
        public bool $sourceCreated,
        public ?string $buriFactId = null,
        public array $citationIds = [],
    ) {
    }

    /**
     * Returns the serialisable shape for the on-disk JSON row.
     *
     * @return WriteBackArray
     */
    public function toArray(): array
    {
        return [
            'deatFactId'    => $this->deatFactId,
            'buriFactId'    => $this->buriFactId,
            'sourceXref'    => $this->sourceXref,
            'sourceCreated' => $this->sourceCreated,
            'citationIds'   => $this->citationIds,
        ];
    }

    /**
     * Reconstructs a write-back from an untrusted decoded row, narrowing every field.
     *
     * @param array<string, mixed> $row The decoded write-back array.
     *
     * @return self The reconstructed write-back.
     *
     * @throws InvalidArgumentException When a required field is missing or mistyped.
     */
    public static function fromArray(array $row): self
    {
        $deatFactId    = $row['deatFactId'] ?? null;
        $sourceXref    = $row['sourceXref'] ?? null;
        $sourceCreated = $row['sourceCreated'] ?? null;
        $buriFactId    = $row['buriFactId'] ?? null;
        $citationIds   = $row['citationIds'] ?? [];

        if (
            !is_string($deatFactId)
            || !is_string($sourceXref)
            || !is_bool($sourceCreated)
        ) {
            throw new InvalidArgumentException('Write-back row is missing required fields or has invalid types.');
        }

        if (
            ($buriFactId !== null)
            && !is_string($buriFactId)
        ) {
            throw new InvalidArgumentException('Write-back buriFactId must be a string or null.');
        }

        if (
            !is_array($citationIds)
            || !array_is_list($citationIds)
        ) {
            throw new InvalidArgumentException('Write-back citationIds must be a list.');
        }

        $narrowedCitationIds = [];

        foreach ($citationIds as $citationId) {
            if (!is_string($citationId)) {
                throw new InvalidArgumentException('Write-back citationIds must be a list of strings.');
            }

            $narrowedCitationIds[] = $citationId;
        }

        return new self($deatFactId, $sourceXref, $sourceCreated, $buriFactId, $narrowedCitationIds);
    }
}
