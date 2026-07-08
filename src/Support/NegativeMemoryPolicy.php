<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\NegativeMemoryEntry;
use MagicSunday\ObituaryMatcher\Domain\SearchSignature;

/**
 * The re-search policy (§5.2d): decides, from a person's recorded negative memory and the search that
 * would run now, whether re-searching should be suppressed. A search is suppressed only while a genuine
 * miss is BOTH still fresh (recorded within the TTL) AND still describes the same search (the recorded
 * signature matches the current one). Once the TTL elapses or the person's searchable data changes, the
 * person is eligible again — and an explicit operator override always bypasses this, which is expressed
 * by the caller simply not consulting the policy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class NegativeMemoryPolicy
{
    /**
     * @var int The default time-to-live of a negative memory in seconds (30 days): after this a person
     *          whose search came back empty is searched again, in case new notices were published.
     */
    public const int DEFAULT_TTL_SECONDS = 2_592_000;

    /**
     * Constructor.
     *
     * @param int $ttlSeconds The time-to-live of a negative memory in seconds.
     */
    public function __construct(
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * Whether a re-search of the person should be suppressed given their recorded negative memory and
     * the signature of the search that would run now.
     *
     * @param NegativeMemoryEntry|null $memory  The person's recorded negative memory, or null when none.
     * @param SearchSignature          $current The signature of the search that would run now.
     * @param int                      $now     The current Unix timestamp.
     *
     * @return bool True when the fresh, same-signature miss means the search should be skipped.
     */
    public function suppresses(?NegativeMemoryEntry $memory, SearchSignature $current, int $now): bool
    {
        if (!$memory instanceof NegativeMemoryEntry) {
            return false;
        }

        if (!$memory->signature->equals($current)) {
            return false;
        }

        return ($now - $memory->recordedAt) < $this->ttlSeconds;
    }
}
