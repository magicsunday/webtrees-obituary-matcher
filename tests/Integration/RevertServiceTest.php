<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Individual;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\RevertConsistencyGate;
use MagicSunday\ObituaryMatcher\Webtrees\RevertOutcome;
use MagicSunday\ObituaryMatcher\Webtrees\RevertPreconditionException;
use MagicSunday\ObituaryMatcher\Webtrees\RevertReason;
use MagicSunday\ObituaryMatcher\Webtrees\RevertResult;
use MagicSunday\ObituaryMatcher\Webtrees\RevertService;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackReverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Integration tests for {@see RevertService}: it tests ONLY the orchestration of the GEDCOM revert, the
 * consistency gate and the store transition into a single typed outcome. The reverter is an injected
 * STUB (so the test never exercises real GEDCOM deletion — that is {@see WriteBackReverterTest}) and the
 * store is a mock so the transition is observed, not performed. It is an integration test because the
 * store-transition-failed arm calls `Log::addErrorLog()`, which needs the booted DB.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RevertService::class)]
#[UsesClass(RevertOutcome::class)]
#[UsesClass(RevertReason::class)]
#[UsesClass(RevertConsistencyGate::class)]
#[UsesClass(RevertResult::class)]
#[UsesClass(WriteBackReverter::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(WriteBack::class)]
#[UsesClass(MatchStatus::class)]
final class RevertServiceTest extends IntegrationTestCase
{
    /**
     * The source URL used for every row under test.
     */
    private const string URL = 'https://example.test/notice';

    /**
     * A clean reverter result with a consistent count transitions the store to Pending.
     *
     * @return void
     */
    #[Test]
    public function aCleanRevertTransitionsTheStore(): void
    {
        $reverter = $this->createStub(WriteBackReverter::class);
        $reverter->method('revert')->willReturn(new RevertResult(['deat-1']));

        $store = $this->createMock(MatchStore::class);
        $store->expects(self::once())->method('revert')->with('I1', self::URL);

        $row = $this->confirmedRow(new WriteBack('deat-1', '@S1@', true));

        $outcome = (new RevertService($reverter))->revert($this->createStub(Individual::class), $row, $store, false);

        self::assertSame(RevertReason::Reverted, $outcome->reason);
        self::assertSame(1, $outcome->deletedCount);
    }

    /**
     * A reverter that refuses (precondition) maps to refused-edited and never transitions the store.
     *
     * @return void
     */
    #[Test]
    public function aReverterRefusalMapsToRefusedEdited(): void
    {
        $reverter = $this->createStub(WriteBackReverter::class);
        $reverter->method('revert')->willThrowException(new RevertPreconditionException('edited'));

        $store = $this->createMock(MatchStore::class);
        $store->expects(self::never())->method('revert');

        $row = $this->confirmedRow(new WriteBack('deat-1', '@S1@', true));

        $outcome = (new RevertService($reverter))->revert($this->createStub(Individual::class), $row, $store, false);

        self::assertSame(RevertReason::RefusedEdited, $outcome->reason);
    }

    /**
     * A forced mixed partial (1 of 2 targets deleted) reports partial and leaves the store unchanged.
     *
     * @return void
     */
    #[Test]
    public function aForcedMixedPartialDoesNotTransitionTheStore(): void
    {
        $reverter = $this->createStub(WriteBackReverter::class);
        $reverter->method('revert')->willReturn(new RevertResult(['deat-1'])); // only 1 of 2 deleted

        $store = $this->createMock(MatchStore::class);
        $store->expects(self::never())->method('revert');

        $row = $this->confirmedRow(new WriteBack('deat-1', '@S1@', true, 'buri-1')); // targetCount = 2

        $outcome = (new RevertService($reverter))->revert($this->createStub(Individual::class), $row, $store, true);

        self::assertSame(RevertReason::Partial, $outcome->reason);
        self::assertSame(1, $outcome->deletedCount);
        self::assertSame(2, $outcome->targetCount);
    }

    /**
     * A store transition that throws after a consistent revert reports store-transition-failed and keeps
     * the counts (the facts are already gone).
     *
     * @return void
     */
    #[Test]
    public function aFailedStoreTransitionReportsStoreTransitionFailedWithCounts(): void
    {
        $reverter = $this->createStub(WriteBackReverter::class);
        $reverter->method('revert')->willReturn(new RevertResult(['deat-1']));

        $store = $this->createMock(MatchStore::class);
        $store->expects(self::once())->method('revert')->willThrowException(new RuntimeException('disk full'));

        $row = $this->confirmedRow(new WriteBack('deat-1', '@S1@', true));

        $outcome = (new RevertService($reverter))->revert($this->createStub(Individual::class), $row, $store, false);

        self::assertSame(RevertReason::StoreTransitionFailed, $outcome->reason);
        self::assertSame(1, $outcome->deletedCount);
        self::assertSame(1, $outcome->targetCount);
    }

    /**
     * A corrupt recorded write-back maps to invalid-write-back; the reverter and store are never touched.
     *
     * @return void
     */
    #[Test]
    public function aCorruptWriteBackReportsInvalidWriteBack(): void
    {
        $reverter = $this->createMock(WriteBackReverter::class);
        $reverter->expects(self::never())->method('revert');

        $store = $this->createMock(MatchStore::class);
        $store->expects(self::never())->method('revert');

        // A write-back array missing the required deatFactId: StoredMatch::fromArray accepts any array,
        // but WriteBack::fromArray rejects it inside the service.
        $row = new StoredMatch('I1', self::URL, MatchStatus::Confirmed, [], null, ['buriFactId' => null]);

        $outcome = (new RevertService($reverter))->revert($this->createStub(Individual::class), $row, $store, false);

        self::assertSame(RevertReason::InvalidWriteBack, $outcome->reason);
    }

    /**
     * Builds a confirmed store row carrying the given write-back.
     *
     * @param WriteBack $writeBack The recorded write-back.
     *
     * @return StoredMatch The confirmed row.
     */
    private function confirmedRow(WriteBack $writeBack): StoredMatch
    {
        return new StoredMatch('I1', self::URL, MatchStatus::Confirmed, [], null, $writeBack->toArray());
    }
}
