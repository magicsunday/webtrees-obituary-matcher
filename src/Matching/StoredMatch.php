<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;

use function is_array;
use function is_string;
use function sprintf;

/**
 * A persisted match suggestion: the candidate it belongs to, the source notice URL, its lifecycle
 * status, the trusted scoring payload (a verbatim {@see ClassifiedMatch::toArray()} shape produced
 * by the engine), an optional rejection reason and the confirm write-back (null until confirmed).
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 * @phpstan-import-type WriteBackArray from WriteBack
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class StoredMatch
{
    /**
     * Constructor.
     *
     * @param string               $personId    The candidate identifier.
     * @param string               $obituaryUrl The source notice URL (raw, pre-normalisation).
     * @param MatchStatus          $status      The lifecycle status.
     * @param ClassifiedMatchArray $match       The trusted scoring payload from the engine.
     * @param string|null          $reason      The rejection reason, if any.
     * @param WriteBackArray|null  $writeBack   The persisted confirm write-back, or null until confirmed.
     */
    public function __construct(
        public string $personId,
        public string $obituaryUrl,
        public MatchStatus $status,
        public array $match,
        public ?string $reason = null,
        public ?array $writeBack = null,
    ) {
    }

    /**
     * Returns the serialisable shape of this stored match for the on-disk JSON row.
     *
     * @return array{
     *     personId: string,
     *     obituaryUrl: string,
     *     status: string,
     *     match: ClassifiedMatchArray,
     *     reason: string|null,
     *     writeBack: WriteBackArray|null
     * }
     */
    public function toArray(): array
    {
        return [
            'personId'    => $this->personId,
            'obituaryUrl' => $this->obituaryUrl,
            'status'      => $this->status->value,
            'match'       => $this->match,
            'reason'      => $this->reason,
            'writeBack'   => $this->writeBack,
        ];
    }

    /**
     * Reconstructs a stored match from an untrusted on-disk JSON row. Every consumed key is narrowed
     * and the status backing value is validated, so a corrupt or hostile row is rejected rather than
     * silently coerced.
     *
     * @param array<string, mixed> $row The decoded JSON row read back from disk.
     *
     * @return self The reconstructed stored match.
     *
     * @throws CorruptMatchRowException When a required key is missing, mistyped or carries an
     *                                  unknown status.
     */
    public static function fromArray(array $row): self
    {
        $personId    = $row['personId'] ?? null;
        $obituaryUrl = $row['obituaryUrl'] ?? null;
        $status      = $row['status'] ?? null;
        $match       = $row['match'] ?? null;

        if (
            !is_string($personId)
            || !is_string($obituaryUrl)
            || !is_string($status)
            || !is_array($match)
        ) {
            throw new CorruptMatchRowException('Stored match row is missing required keys or has invalid types.');
        }

        $matchStatus = MatchStatus::tryFrom($status);

        if (!$matchStatus instanceof MatchStatus) {
            throw new CorruptMatchRowException(
                sprintf('Stored match row carries an unknown status: %s', $status)
            );
        }

        $reason = $row['reason'] ?? null;

        if (
            ($reason !== null)
            && !is_string($reason)
        ) {
            throw new CorruptMatchRowException('Stored match row has an invalid reason.');
        }

        $writeBack = $row['writeBack'] ?? null;

        if (
            ($writeBack !== null)
            && !is_array($writeBack)
        ) {
            throw new CorruptMatchRowException('Stored match row has an invalid write-back payload.');
        }

        /**
         * @var ClassifiedMatchArray $match
         */

        /**
         * @var WriteBackArray|null $writeBack
         */

        return new self(
            $personId,
            $obituaryUrl,
            $matchStatus,
            $match,
            $reason,
            $writeBack,
        );
    }
}
