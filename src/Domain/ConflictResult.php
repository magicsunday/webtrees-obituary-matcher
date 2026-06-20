<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * The aggregated conflict penalty and reasons collected during scoring.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ConflictResult
{
    /**
     * Constructor.
     *
     * @param int                  $penalty Points to deduct from the total score.
     * @param list<ConflictReason> $reasons All individual field-level conflicts.
     */
    public function __construct(
        public int $penalty,
        public array $reasons,
    ) {
    }

    /**
     * Returns whether at least one hard conflict was detected.
     *
     * @return bool
     */
    public function hasHardConflict(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->severity === ConflictSeverity::Hard) {
                return true;
            }
        }

        return false;
    }
}
