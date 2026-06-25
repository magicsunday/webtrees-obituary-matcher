<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

/**
 * The typed result of a {@see RevertService::revert()} run: the classified {@see RevertReason} plus the
 * fact counts the presentations need. Built only through the named constructors so an outcome is always
 * internally consistent (a reverted outcome's target equals its deleted count).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RevertOutcome
{
    /**
     * Constructor. Private to force construction through the named constructors.
     *
     * @param RevertReason $reason       The classified outcome.
     * @param int          $deletedCount The number of facts the revert deleted.
     * @param int          $targetCount  The number of facts the revert was responsible for.
     */
    private function __construct(
        public RevertReason $reason,
        public int $deletedCount,
        public int $targetCount,
    ) {
    }

    /**
     * A clean revert: every target deleted and the store returned to Pending.
     *
     * @param int $deletedCount The number of facts deleted.
     *
     * @return self The reverted outcome.
     */
    public static function reverted(int $deletedCount): self
    {
        return new self(RevertReason::Reverted, $deletedCount, $deletedCount);
    }

    /**
     * A normal-mode refusal: a written fact was edited or removed, so nothing was deleted.
     *
     * @return self The refused outcome.
     */
    public static function refusedEdited(): self
    {
        return new self(RevertReason::RefusedEdited, 0, 0);
    }

    /**
     * A forced mixed partial: some targets deleted, an edited target still standing.
     *
     * @param int $deletedCount The number of facts deleted.
     * @param int $targetCount  The number of facts the revert was responsible for.
     *
     * @return self The partial outcome.
     */
    public static function partial(int $deletedCount, int $targetCount): self
    {
        return new self(RevertReason::Partial, $deletedCount, $targetCount);
    }

    /**
     * The facts were deleted but the store transition failed. The counts are kept (the facts ARE gone)
     * so a caller can report how many were removed before the store fell out of sync.
     *
     * @param int $deletedCount The number of facts deleted before the transition failed.
     * @param int $targetCount  The number of facts the revert was responsible for.
     *
     * @return self The store-transition-failed outcome.
     */
    public static function storeTransitionFailed(int $deletedCount, int $targetCount): self
    {
        return new self(RevertReason::StoreTransitionFailed, $deletedCount, $targetCount);
    }

    /**
     * The recorded write-back could not be parsed.
     *
     * @return self The invalid-write-back outcome.
     */
    public static function invalidWriteBack(): self
    {
        return new self(RevertReason::InvalidWriteBack, 0, 0);
    }

    /**
     * Whether the revert fully completed (facts removed and the row returned to Pending).
     *
     * @return bool True only for the reverted outcome.
     */
    public function isSuccess(): bool
    {
        return $this->reason === RevertReason::Reverted;
    }
}
