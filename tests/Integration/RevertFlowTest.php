<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\RevertConsistencyGate;
use MagicSunday\ObituaryMatcher\Webtrees\RevertOutcome;
use MagicSunday\ObituaryMatcher\Webtrees\RevertReason;
use MagicSunday\ObituaryMatcher\Webtrees\RevertService;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackReverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function iterator_to_array;

/**
 * Flow tests for the shared revert orchestration ({@see RevertService::revert()}) the
 * `tools/revert.php` CLI and the worklist handler both delegate to. The service runs the GEDCOM revert
 * ({@see WriteBackReverter::revert()}), applies the store-transition consistency gate
 * ({@see RevertConsistencyGate::isConsistent()}) and — only when no module-written fact still stands in
 * the tree — returns the row to Pending ({@see MatchStore::revert()}), classifying the result into a
 * {@see RevertOutcome}. Either every recorded target was deleted, or (under --force) the recorded facts
 * were already absent (orphan repair). A --force MIXED partial — some targets deleted, an edited target
 * still present — must NOT flip the store to Pending, or the store would assert a false truth while an
 * edited module fact remains.
 *
 * The CLI is a thin composition root over option parsing + {@see RevertService}; this test exercises
 * that exact orchestration against a live tree and a real file store, which is the load-bearing path.
 * The raw `tools/revert.php` cannot be driven in-process (it boots its own runtime and exits), so the
 * flow is pinned here through the same service the script composes. The `consistencyMatrix` provider
 * pins the pure {@see RevertConsistencyGate} decision the service relies on.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RevertConsistencyGate::class)]
#[UsesClass(RevertService::class)]
#[UsesClass(RevertOutcome::class)]
#[UsesClass(RevertReason::class)]
#[UsesClass(WriteBackReverter::class)]
#[UsesClass(FileMatchStore::class)]
final class RevertFlowTest extends IntegrationTestCase
{
    use RemovesFlatTempStoreTrait;

    /**
     * The obituary URL every fixture row is keyed by.
     */
    private const string URL = 'https://trauer.example/I1';

    /**
     * The temp store directory created per test.
     *
     * @var string
     */
    private string $dir = '';

    /**
     * Allocate a unique temp store directory for the file-backed match store.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->makeFlatStoreDir('om-revert-');
    }

    /**
     * Remove the temp store directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeFlatStoreDir();

        parent::tearDown();
    }

    /**
     * Build a fixture tree carrying a single individual, with auto-accept on so a written DEAT/BURI
     * lands immediately and the assertions read committed records.
     *
     * @return Tree The freshly-imported fixture tree with I1.
     */
    private function tree(): Tree
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        return $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n"
        );
    }

    /**
     * The file-backed match store rooted at the per-test temp directory.
     *
     * @return MatchStore The store under test.
     */
    private function store(): MatchStore
    {
        return new FileMatchStore($this->dir);
    }

    /**
     * Seed a Confirmed row carrying the given write-back, so the flow has a confirmed, revertable
     * starting state — mirrors the store state the live Confirm action leaves behind.
     *
     * @param WriteBack $writeBack The write-back to persist on the confirmed row.
     *
     * @return void
     */
    private function seedConfirmed(WriteBack $writeBack): void
    {
        $store = $this->store();
        $match = ClassifiedMatch::emptyArray('I1', self::URL);

        $store->upsertPending(new StoredMatch('I1', self::URL, MatchStatus::Pending, $match));
        $store->markConfirmed('I1', self::URL, $writeBack);
    }

    /**
     * The current persisted status of the I1 row, or null when the row is absent.
     *
     * @return MatchStatus|null The row status.
     */
    private function rowStatus(): ?MatchStatus
    {
        $row = $this->store()->findOne('I1', StoredMatchKey::fromUrl(self::URL));

        return $row?->status;
    }

    /**
     * Run the revert flow the CLI composes, delegating to the shared {@see RevertService} exactly as the
     * CLI does: it reads the seeded Confirmed row back from the store (`seedConfirmed()` ran first) and
     * reverts it. Returns whether the service signalled a full revert (the CLI's exit 0).
     *
     * @param Individual $individual The tree person.
     * @param bool       $force      Whether to run in --force mode.
     *
     * @return bool True when the store was reverted (CLI exit 0); false when the gate blocked it.
     */
    private function runFlow(Individual $individual, bool $force): bool
    {
        $row = $this->store()->findOne('I1', StoredMatchKey::fromUrl(self::URL));
        self::assertInstanceOf(StoredMatch::class, $row);

        $outcome = (new RevertService())->revert($individual, $row, $this->store(), $force);

        return $outcome->isSuccess();
    }

    /**
     * Happy path: a confirmed DEAT+BURI confirm reverts cleanly — both facts are deleted from the tree
     * AND the store row is returned to Pending.
     *
     * @return void
     */
    #[Test]
    public function happyPathDeletesBothFactsAndReturnsTheRowToPending(): void
    {
        $tree      = $this->tree();
        $writeBack = (new ObituaryWriteBack())->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', '2023-09-10', self::URL);
        self::assertNotNull($writeBack->buriFactId);
        $this->seedConfirmed($writeBack);

        $reverted = $this->runFlow($this->person('I1', $tree), false);

        self::assertTrue($reverted);
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        self::assertSame(MatchStatus::Pending, $this->rowStatus());
    }

    /**
     * The blocker case: confirm DEAT+BURI, edit the DEAT out-of-band (its md5 id changes), then run the
     * flow with --force. The still-resolving BURI is deleted, but because an edited module-written DEAT
     * still stands, the gate refuses the store transition: the row STAYS Confirmed and the flow signals
     * failure. This proves the store never asserts a false "Pending" while an edited module fact remains.
     *
     * @return void
     */
    #[Test]
    public function mixedPartialForceDeletesTheBuriButLeavesTheStoreConfirmed(): void
    {
        $tree      = $this->tree();
        $writeBack = (new ObituaryWriteBack())->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', '2023-09-10', self::URL);
        self::assertNotNull($writeBack->buriFactId);
        $this->seedConfirmed($writeBack);

        // Edit the DEAT out-of-band — this changes its gedcom, hence its md5 id, so it no longer resolves.
        $this->person('I1', $tree)->updateFact($writeBack->deatFactId, "1 DEAT\n2 DATE 5 SEP 2023\n2 NOTE edited", true);

        $reverted = $this->runFlow($this->person('I1', $tree), true);

        // The flow signalled failure (CLI exit 1): the gate blocked the store transition.
        self::assertFalse($reverted);
        // Force deleted the still-resolving BURI, but the edited DEAT remains.
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        self::assertCount(1, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
        // The store was LEFT UNCHANGED — a false "Pending" while an edited module fact stands is refused.
        self::assertSame(MatchStatus::Confirmed, $this->rowStatus());
    }

    /**
     * Orphan repair: confirm DEAT+BURI, delete BOTH facts out-of-band, then run with --force. Nothing
     * resolves (zero deleted), but because no recorded fact still stands the gate IS consistent and the
     * store is returned to Pending — the orphan-repair path symmetric to confirm.
     *
     * @return void
     */
    #[Test]
    public function orphanRepairForceReturnsTheRowToPendingWhenNothingResolves(): void
    {
        $tree      = $this->tree();
        $writeBack = (new ObituaryWriteBack())->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', '2023-09-10', self::URL);
        self::assertNotNull($writeBack->buriFactId);
        $this->seedConfirmed($writeBack);

        // Remove BOTH written facts out-of-band so neither captured id resolves any more.
        $this->person('I1', $tree)->deleteFact($writeBack->deatFactId, true);
        $this->person('I1', $tree)->deleteFact($writeBack->buriFactId, true);

        $reverted = $this->runFlow($this->person('I1', $tree), true);

        self::assertTrue($reverted);
        self::assertSame(MatchStatus::Pending, $this->rowStatus());
    }

    /**
     * The pure consistency decision the gate encodes, across the full target/deleted/force matrix.
     *
     * @param int  $targetCount  The number of recorded target facts (DEAT, plus BURI when written).
     * @param int  $deletedCount The number of facts the revert actually deleted.
     * @param bool $force        Whether the revert ran in --force mode.
     * @param bool $expected     The expected consistency verdict.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('consistencyMatrix')]
    public function theConsistencyGateEncodesTheStoreTransitionPrecondition(int $targetCount, int $deletedCount, bool $force, bool $expected): void
    {
        self::assertSame($expected, RevertConsistencyGate::isConsistent($targetCount, $deletedCount, $force));
    }

    /**
     * The target/deleted/force → consistency matrix.
     *
     * @return array<string, array{int, int, bool, bool}>
     */
    public static function consistencyMatrix(): array
    {
        return [
            'clean revert: all targets deleted (normal)'    => [2, 2, false, true],
            'clean revert: all targets deleted (force)'     => [2, 2, true, true],
            'orphan repair: nothing resolved under force'   => [2, 0, true, true],
            'mixed partial under force: some left standing' => [2, 1, true, false],
            'nothing deleted without force is inconsistent' => [2, 0, false, false],
            'single-target clean revert'                    => [1, 1, false, true],
            'single-target orphan repair under force'       => [1, 0, true, true],
            'single-target nothing deleted without force'   => [1, 0, false, false],
            'mixed partial without force is inconsistent'   => [2, 1, false, false],
        ];
    }
}
