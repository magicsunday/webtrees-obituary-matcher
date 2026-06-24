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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\RevertPreconditionException;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackReverter;
use PHPUnit\Framework\Attributes\Test;

use function iterator_to_array;

/**
 * Integration tests for {@see WriteBackReverter::revert()} — it reverses a real confirmed write-back
 * over a live tree: it deletes the written DEAT and (when present) BURI fact while keeping the portal
 * source. Normal mode is all-or-nothing (any edited/missing target refuses and deletes nothing);
 * `--force` best-effort deletes whichever targets still resolve.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class WriteBackReverterTest extends IntegrationTestCase
{
    /**
     * Build a fixture tree carrying a single individual. The base bootstrap logs in an administrator
     * (an editor of every tree), and auto-accept is turned ON so a written DEAT/BURI/source lands
     * immediately and the assertions read committed records.
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
     * The revert deletes the written DEAT and BURI facts from a freshly re-fetched individual, returns
     * both deleted ids, and keeps the portal source resolvable in the tree.
     *
     * @return void
     */
    #[Test]
    public function revertDeletesTheWrittenDeatAndBuriAndKeepsTheSource(): void
    {
        $tree      = $this->tree();
        $writeBack = (new ObituaryWriteBack())->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', '2023-09-10', 'https://trauer.example/x');

        self::assertNotNull($writeBack->buriFactId);

        $result = (new WriteBackReverter())->revert($this->person('I1', $tree), $writeBack);

        // Both facts are gone from a freshly re-fetched individual.
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        // The result lists both deleted ids.
        self::assertEqualsCanonicalizing([$writeBack->deatFactId, $writeBack->buriFactId], $result->deletedFactIds);
        // The portal SOUR is KEPT (still resolvable in the tree).
        self::assertInstanceOf(Source::class, Registry::sourceFactory()->make($writeBack->sourceXref, $tree));
    }

    /**
     * A DEAT-only confirm (no cemetery → no BURI) reverts by deleting only the DEAT.
     *
     * @return void
     */
    #[Test]
    public function revertOfADeatOnlyConfirmDeletesOnlyTheDeat(): void
    {
        $tree      = $this->tree();
        $writeBack = (new ObituaryWriteBack())->writeConfirm($this->person('I1', $tree), '2023-09-04', null, null, 'https://trauer.example/x');
        self::assertNull($writeBack->buriFactId);

        $result = (new WriteBackReverter())->revert($this->person('I1', $tree), $writeBack);

        self::assertSame([$writeBack->deatFactId], $result->deletedFactIds);
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
    }

    /**
     * When the DEAT was edited out-of-band since the confirm (its gedcom, hence its md5 id, changed),
     * a normal revert refuses with a RevertPreconditionException and deletes nothing — the untouched
     * BURI must still be present.
     *
     * @return void
     */
    #[Test]
    public function revertRefusesAndDeletesNothingWhenADeatWasEditedSince(): void
    {
        $tree      = $this->tree();
        $i1        = $this->person('I1', $tree);
        $writeBack = (new ObituaryWriteBack())->writeConfirm($i1, '2023-09-04', 'Waldfriedhof', '2023-09-10', 'https://trauer.example/x');

        // Edit the DEAT out-of-band — this changes its gedcom, hence its md5 id.
        $reloaded = $this->person('I1', $tree);
        $reloaded->updateFact($writeBack->deatFactId, "1 DEAT\n2 DATE 5 SEP 2023\n2 NOTE edited", true);

        $this->expectException(RevertPreconditionException::class);

        try {
            (new WriteBackReverter())->revert($this->person('I1', $tree), $writeBack);
        } finally {
            // All-or-nothing: the BURI (untouched) must still be present — nothing was deleted.
            self::assertCount(1, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        }
    }

    /**
     * With `--force` the revert best-effort deletes the still-resolving BURI and leaves the edited DEAT
     * (whose id changed) in place, returning only the BURI id.
     *
     * @return void
     */
    #[Test]
    public function forceBestEffortDeletesTheResolvableBuriAndLeavesTheEditedDeat(): void
    {
        $tree      = $this->tree();
        $i1        = $this->person('I1', $tree);
        $writeBack = (new ObituaryWriteBack())->writeConfirm($i1, '2023-09-04', 'Waldfriedhof', '2023-09-10', 'https://trauer.example/x');

        $reloaded = $this->person('I1', $tree);
        $reloaded->updateFact($writeBack->deatFactId, "1 DEAT\n2 DATE 5 SEP 2023\n2 NOTE edited", true);

        $result = (new WriteBackReverter())->revert($this->person('I1', $tree), $writeBack, true);

        // Force: the still-resolving BURI is deleted; the edited DEAT (id changed) is not.
        self::assertSame([$writeBack->buriFactId], $result->deletedFactIds);
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        self::assertCount(1, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
    }
}
