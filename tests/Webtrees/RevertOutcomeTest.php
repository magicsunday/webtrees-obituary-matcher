<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Webtrees\RevertOutcome;
use MagicSunday\ObituaryMatcher\Webtrees\RevertReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see RevertOutcome} value object: each named constructor pins its reason and
 * counts, and only the reverted outcome reports success.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RevertOutcome::class)]
#[CoversClass(RevertReason::class)]
final class RevertOutcomeTest extends TestCase
{
    /**
     * A reverted outcome carries the deleted count and reports success.
     *
     * @return void
     */
    #[Test]
    public function revertedCarriesTheDeletedCountAndIsSuccess(): void
    {
        $outcome = RevertOutcome::reverted(2);

        self::assertSame(RevertReason::Reverted, $outcome->reason);
        self::assertSame(2, $outcome->deletedCount);
        self::assertTrue($outcome->isSuccess());
    }

    /**
     * A partial outcome keeps both the deleted and the target count and is not a success.
     *
     * @return void
     */
    #[Test]
    public function partialKeepsBothCountsAndIsNotSuccess(): void
    {
        $outcome = RevertOutcome::partial(1, 2);

        self::assertSame(RevertReason::Partial, $outcome->reason);
        self::assertSame(1, $outcome->deletedCount);
        self::assertSame(2, $outcome->targetCount);
        self::assertFalse($outcome->isSuccess());
    }

    /**
     * The remaining failure outcomes report their reason and are never a success.
     *
     * @return void
     */
    #[Test]
    public function failureOutcomesAreNotSuccess(): void
    {
        self::assertSame(RevertReason::RefusedEdited, RevertOutcome::refusedEdited()->reason);
        self::assertSame(RevertReason::InvalidWriteBack, RevertOutcome::invalidWriteBack()->reason);
        self::assertFalse(RevertOutcome::refusedEdited()->isSuccess());
    }

    /**
     * A store-transition failure keeps the counts (the facts are already gone) and is not a success.
     *
     * @return void
     */
    #[Test]
    public function storeTransitionFailedKeepsTheCounts(): void
    {
        $outcome = RevertOutcome::storeTransitionFailed(2, 2);

        self::assertSame(RevertReason::StoreTransitionFailed, $outcome->reason);
        self::assertSame(2, $outcome->deletedCount);
        self::assertSame(2, $outcome->targetCount);
        self::assertFalse($outcome->isSuccess());
    }
}
