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
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Support\MalformedDeathDateException;
use MagicSunday\ObituaryMatcher\Webtrees\DeathDateAlreadyPresentException;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWriteBack;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackPreconditionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function count;
use function date;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function str_contains;
use function strpos;

/**
 * Integration tests for {@see ObituaryWriteBack::writeConfirm()} — the actual sourced write over a
 * real tree: precondition rejections, the live re-check race guard, the dated+sourced DEAT fact and
 * the deatFactId capture round-trip, plus the optional sourced BURI (cemetery → PLAC, funeral → DATE)
 * with its buriFactId capture and the existing-burial skip.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryWriteBackTest extends IntegrationTestCase
{
    /**
     * The writer under test.
     *
     * @return ObituaryWriteBack The production writer.
     */
    private function writer(): ObituaryWriteBack
    {
        return new ObituaryWriteBack();
    }

    /**
     * Build a fixture tree carrying the described individuals. The base bootstrap logs in an
     * administrator (an editor of every tree), and auto-accept is turned ON so a written DEAT/source
     * lands immediately and the assertions read committed records.
     *
     * @return Tree The freshly-imported fixture tree with I1–I7.
     */
    private function tree(): Tree
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');

        return $this->importFixtureTree(
            "0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n"
            . "0 @I2@ INDI\n1 NAME Erna /Vorbild/\n1 SEX F\n"
            . "0 @I3@ INDI\n1 NAME Hans /Tot/\n1 SEX M\n1 DEAT\n2 DATE 1990\n"
            . "0 @I4@ INDI\n1 NAME Klara /Grab/\n1 SEX F\n1 BURI\n2 DATE 12 MAY 1985\n"
            . "0 @I5@ INDI\n1 NAME Paul /Leer/\n1 SEX M\n1 DEAT\n"
            . "0 @I6@ INDI\n1 NAME Greta /Asche/\n1 SEX F\n1 CREM\n2 DATE 03 MAR 1995\n"
            . "0 @I7@ INDI\n1 NAME Wili /Grablos/\n1 SEX M\n1 BIRT\n2 DATE 1 JAN 1940\n1 BURI\n"
        );
    }

    /**
     * Count the DEAT facts on a freshly re-fetched individual.
     *
     * @param string $xref The individual XREF.
     * @param Tree   $tree The fixture tree.
     *
     * @return int The number of DEAT facts.
     */
    private function deatCount(string $xref, Tree $tree): int
    {
        return count($this->person($xref, $tree)->facts(['DEAT'], false, null, true));
    }

    /**
     * writeConfirm writes a dated, sourced DEAT and returns a WriteBack whose deatFactId resolves the
     * just-written fact (the capture round-trip), with the reserved fields at their 2d-3a values.
     *
     * @return void
     */
    #[Test]
    public function writesADatedSourcedDeatAndReturnsTheWriteBack(): void
    {
        $tree = $this->tree();
        $i1   = $this->person('I1', $tree);

        $writeBack = $this->writer()->writeConfirm($i1, '2023-09-04', null, null, 'https://trauer.example/x');

        // Re-fetch so the assertion reads the committed record, not the in-memory copy.
        $reloaded = $this->person('I1', $tree);
        $facts    = iterator_to_array($reloaded->facts(['DEAT'], false, null, true));

        self::assertCount(1, $facts);

        $fact   = $facts[0];
        $gedcom = $fact->gedcom();

        // Pin the FULL nested GEDCOM body in one shot (substituting the dynamic source xref and the
        // confirm date, computed the SAME way to avoid a midnight-boundary flake): four independent
        // substring checks would pass on a fact whose lines were emitted at the wrong nesting level or
        // out of order. The exact body proves the 2 SOUR → 3 PAGE → 3 DATA → 4 DATE nesting.
        $expectedGedcom = sprintf(
            "1 DEAT\n2 DATE 4 SEP 2023\n2 SOUR @%s@\n3 PAGE https://trauer.example/x\n3 DATA\n4 DATE %s",
            $writeBack->sourceXref,
            GedcomDateConverter::toGedcom(date('Y-m-d'))
        );

        self::assertSame($expectedGedcom, $gedcom);

        // The id invariant — the riskiest logic. writeConfirm computes the returned id as the md5 of the
        // exact gedcom it writes; this asserts it EQUALS the STORED fact's id (Fact::id() is the md5 of the
        // re-parsed stored gedcom), pinning the verbatim-storage invariant (updateRecord stores a
        // non-trailing fact byte-for-byte) that the merged Revert relies on to resolve facts by id.
        // assertNotNull alone would be insufficient. The competing-DEAT case (two DEATs, the returned id
        // must resolve the NEW dated fact) is exercised by leavesABareDeatUntouchedAndAddsADatedDeat().
        self::assertSame($fact->id(), $writeBack->deatFactId);
        self::assertNotSame('', $writeBack->deatFactId);

        self::assertTrue($writeBack->sourceCreated);
        self::assertNull($writeBack->buriFactId);
        self::assertSame([], $writeBack->citationIds);
    }

    /**
     * A second confirm on the SAME host reuses the portal source: same xref, sourceCreated=false on the
     * second, and exactly ONE source carrying the module's REFN marker in the tree.
     *
     * @return void
     */
    #[Test]
    public function reusesThePortalSourceOnASecondSameHostConfirm(): void
    {
        $tree = $this->tree();
        $i1   = $this->person('I1', $tree);
        $i2   = $this->person('I2', $tree);

        $first  = $this->writer()->writeConfirm($i1, '2023-09-04', null, null, 'https://trauer.example/x');
        $second = $this->writer()->writeConfirm($i2, '2024-01-15', null, null, 'https://www.trauer.example/y?utm=z');

        self::assertSame($first->sourceXref, $second->sourceXref);
        self::assertTrue($first->sourceCreated);
        self::assertFalse($second->sourceCreated);

        // Positive anchor: the second confirm must actually have written its dated DEAT citing the reused
        // source — else a silent citation no-op would still pass the source-identity/count assertions.
        $i2Facts = iterator_to_array($this->person('I2', $tree)->facts(['DEAT'], false, null, true));
        self::assertCount(1, $i2Facts);
        self::assertStringContainsString('2 DATE 15 JAN 2024', $i2Facts[0]->gedcom());
        self::assertStringContainsString('2 SOUR @' . $second->sourceXref . '@', $i2Facts[0]->gedcom());

        // Exactly ONE portal source for the host — else a duplicate-source regression slips through.
        // Auto-accept is ON in this fixture, so the created source committed to the `sources` table.
        $marker = 'obituary-matcher:portal:trauer.example';
        $count  = 0;

        $gedcoms = DB::table('sources')
            ->where('s_file', '=', $tree->id())
            ->pluck('s_gedcom');

        foreach ($gedcoms as $gedcom) {
            if (is_string($gedcom) && str_contains($gedcom, $marker)) {
                ++$count;
            }
        }

        self::assertSame(1, $count, 'the portal source must be reused, not duplicated');
    }

    /**
     * A live re-check blocks the write when the individual already has a dated DEAT: the exception is
     * thrown AND no DEAT fact is added.
     *
     * @return void
     */
    #[Test]
    public function throwsWhenTheIndividualAlreadyHasADeathDate(): void
    {
        $tree   = $this->tree();
        $i3     = $this->person('I3', $tree);
        $before = $this->deatCount('I3', $tree);

        try {
            $this->writer()->writeConfirm($i3, '2023-09-04', null, null, 'https://trauer.example/x');
            self::fail('expected a DeathDateAlreadyPresentException');
        } catch (DeathDateAlreadyPresentException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I3', $tree), 'no DEAT fact may be written');
    }

    /**
     * A dated BURI (no DEAT) also blocks: getDeathDate covers DEAT/BURI/CREM, so the live re-check
     * refuses the write and adds nothing.
     *
     * @return void
     */
    #[Test]
    public function aDatedBuriAlsoBlocks(): void
    {
        $tree   = $this->tree();
        $i4     = $this->person('I4', $tree);
        $before = $this->deatCount('I4', $tree);

        try {
            $this->writer()->writeConfirm($i4, '2023-09-04', null, null, 'https://trauer.example/x');
            self::fail('expected a DeathDateAlreadyPresentException');
        } catch (DeathDateAlreadyPresentException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I4', $tree), 'no DEAT fact may be written');
    }

    /**
     * A dated CREM (no DEAT) also blocks: getDeathDate covers DEAT/BURI/CREM, so a regression narrowing
     * the live re-check to DEAT+BURI would be caught here. The exception is thrown and nothing is added.
     *
     * @return void
     */
    #[Test]
    public function aDatedCremAlsoBlocks(): void
    {
        $tree   = $this->tree();
        $i6     = $this->person('I6', $tree);
        $before = $this->deatCount('I6', $tree);

        try {
            $this->writer()->writeConfirm($i6, '2023-09-04', null, null, 'https://trauer.example/x');
            self::fail('expected a DeathDateAlreadyPresentException');
        } catch (DeathDateAlreadyPresentException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I6', $tree), 'no DEAT fact may be written');
    }

    /**
     * A bare DEAT (no DATE) does NOT count as a death date: the write succeeds, adding a SECOND, dated
     * DEAT, and the original bare DEAT survives byte-unchanged. This is also the competing-DEAT case for
     * the returned-id invariant — with two DEAT facts present, the returned deatFactId (the md5 of the
     * exact gedcom written) must resolve the NEW sourced obituary fact, not the pre-existing bare one.
     *
     * @return void
     */
    #[Test]
    public function leavesABareDeatUntouchedAndAddsADatedDeat(): void
    {
        $tree = $this->tree();
        $i5   = $this->person('I5', $tree);

        $writeBack = $this->writer()->writeConfirm($i5, '2023-09-04', null, null, 'https://trauer.example/x');

        $facts = iterator_to_array($this->person('I5', $tree)->facts(['DEAT'], false, null, true));

        self::assertCount(2, $facts, 'a new dated DEAT is added next to the bare one');

        $bare  = null;
        $dated = null;

        foreach ($facts as $fact) {
            if (str_contains($fact->gedcom(), '2 DATE')) {
                $dated = $fact;
            } else {
                $bare = $fact;
            }
        }

        self::assertNotNull($bare, 'the original bare DEAT must still be present');
        self::assertNotNull($dated, 'the new dated DEAT must be present');
        self::assertSame('1 DEAT', $bare->gedcom(), 'the bare DEAT must be byte-unchanged');

        // The new fact must be the sourced obituary write (date + citation), not merely "some dated DEAT".
        self::assertStringContainsString('2 DATE 4 SEP 2023', $dated->gedcom());
        self::assertStringContainsString('2 SOUR @' . $writeBack->sourceXref . '@', $dated->gedcom());
        self::assertStringContainsString('3 PAGE https://trauer.example/x', $dated->gedcom());

        // The returned id (md5 of the exact written gedcom) resolves the dated obituary fact among the two
        // DEATs, never the bare one.
        self::assertSame($dated->id(), $writeBack->deatFactId);
        self::assertNotSame($bare->id(), $writeBack->deatFactId);
    }

    /**
     * Every precondition rejection path: a CR/LF GEDCOM-line injection, a non-http(s) scheme, an
     * unparseable (empty) host, and a control char in the URL. Each throws WriteBackPreconditionException
     * AND writes zero DEAT facts — the no-write invariant is the point of the precondition guard.
     *
     * @param string $url A URL the precondition guard must reject.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('rejectedUrlProvider')]
    public function rejectsAnUnsafeUrl(string $url): void
    {
        $tree   = $this->tree();
        $i1     = $this->person('I1', $tree);
        $before = $this->deatCount('I1', $tree);

        try {
            $this->writer()->writeConfirm($i1, '2023-09-04', null, null, $url);
            self::fail('expected a WriteBackPreconditionException');
        } catch (WriteBackPreconditionException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I1', $tree), 'no DEAT fact may be written on a rejected URL');
    }

    /**
     * URLs the precondition guard must reject, one row per branch: a bare LF and a CRLF (the
     * control-char guard, blocking GEDCOM-line injection), a non-http(s) scheme (the scheme guard), an
     * unparseable empty host (the `$host === ''` guard), and a non-newline control char inside the URL.
     *
     * @return array<string, array{0: string}> The named rejected-URL rows.
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'bare LF injection'   => ["https://trauer.example/x\n2 NOTE injected"],
            'CRLF injection'      => ["https://trauer.example/x\r\n2 NOTE injected"],
            'non-http scheme'     => ['ftp://trauer.example/x'],
            'empty host'          => ['https:///x'],
            'control char in url' => ["https://trau\x01er.example/x"],
        ];
    }

    /**
     * A malformed (non-calendar) ISO date is rejected by the converter before any write: the exception
     * propagates AND zero DEAT facts are written.
     *
     * @return void
     */
    #[Test]
    public function rejectsAMalformedDate(): void
    {
        $tree   = $this->tree();
        $i1     = $this->person('I1', $tree);
        $before = $this->deatCount('I1', $tree);

        try {
            $this->writer()->writeConfirm($i1, '2023-02-31', null, null, 'https://trauer.example/x');
            self::fail('expected a MalformedDeathDateException');
        } catch (MalformedDeathDateException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I1', $tree), 'no DEAT fact may be written on a malformed date');
    }

    /**
     * A cemetery (with a funeral date) writes a sourced BURI citing the same portal source as the DEAT,
     * with the DATE preceding the PLAC line.
     *
     * @return void
     */
    #[Test]
    public function aCemeteryWritesASourcedBurialCitingTheSameSource(): void
    {
        $tree = $this->tree();

        $writeBack = $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof Beispielstadt', '2023-09-10', 'https://trauer.example/x');

        self::assertNotNull($writeBack->buriFactId);

        $buri = iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true));

        self::assertCount(1, $buri);
        $gedcom = $buri[0]->gedcom();
        self::assertStringContainsString('2 PLAC Waldfriedhof Beispielstadt', $gedcom);
        self::assertStringContainsString('2 DATE 10 SEP 2023', $gedcom);
        self::assertStringContainsString('2 SOUR @' . $writeBack->sourceXref . '@', $gedcom);
        self::assertStringContainsString('3 PAGE https://trauer.example/x', $gedcom);
        self::assertTrue(strpos($gedcom, '2 DATE') < strpos($gedcom, '2 PLAC'), 'BURI DATE must precede PLAC');
    }

    /**
     * No cemetery → no BURI is written and buriFactId stays null.
     *
     * @return void
     */
    #[Test]
    public function noCemeteryWritesNoBurial(): void
    {
        $tree      = $this->tree();
        $writeBack = $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', null, null, 'https://trauer.example/x');

        self::assertNull($writeBack->buriFactId);
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
    }

    /**
     * A whitespace-only cemetery normalises to absent → no BURI is written.
     *
     * @return void
     */
    #[Test]
    public function aWhitespaceOnlyCemeteryWritesNoBurial(): void
    {
        $tree      = $this->tree();
        $writeBack = $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', '   ', '2023-09-10', 'https://trauer.example/x');

        self::assertNull($writeBack->buriFactId);
        self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
    }

    /**
     * A cemetery without a funeral date writes a BURI carrying the PLAC but no DATE line.
     *
     * @return void
     */
    #[Test]
    public function aCemeteryWithoutAFuneralDateWritesABurialWithoutADateLine(): void
    {
        $tree      = $this->tree();
        $writeBack = $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', null, 'https://trauer.example/x');

        self::assertNotNull($writeBack->buriFactId);
        $gedcom = iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true))[0]->gedcom();
        self::assertStringContainsString('2 PLAC Waldfriedhof', $gedcom);
        self::assertStringNotContainsString('2 DATE', $gedcom);
    }

    /**
     * An existing BURI skips the burial write (buriFactId null) but the death still writes.
     *
     * @return void
     */
    #[Test]
    public function anExistingBurialSkipsTheBurialWriteButTheDeathStillWrites(): void
    {
        // I7 carries a BARE `1 BURI` (no date) — verified not to trip getDeathDate()->isOK()
        // (getAllDeathDates only matches a death event WITH a `2 DATE`), so the death-date guard passes,
        // the DEAT writes, and the existing-BURI check skips the burial.
        $tree      = $this->tree();
        $writeBack = $this->writer()->writeConfirm($this->person('I7', $tree), '2023-09-04', 'Waldfriedhof', '2023-09-10', 'https://trauer.example/x');

        self::assertNull($writeBack->buriFactId);
        self::assertNotSame('', $writeBack->deatFactId);
        self::assertCount(1, iterator_to_array($this->person('I7', $tree)->facts(['BURI'], false, null, true)));
        self::assertCount(1, iterator_to_array($this->person('I7', $tree)->facts(['DEAT'], false, null, true)));
    }

    /**
     * A control char in the cemetery aborts atomically: the precondition guard runs before any write,
     * so neither DEAT nor BURI is written.
     *
     * @return void
     */
    #[Test]
    public function aControlCharCemeteryAbortsWithNothingWritten(): void
    {
        $tree = $this->tree();

        $this->expectException(WriteBackPreconditionException::class);

        try {
            $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', "Wald\nfriedhof", '2023-09-10', 'https://trauer.example/x');
        } finally {
            // Atomic: neither DEAT nor BURI written (the cemetery guard runs before find-or-create +
            // any createFact).
            self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
            self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        }
    }

    /**
     * A malformed funeral date WITH a cemetery aborts atomically: the funeral date is validated (because
     * a cemetery is present) before any write, so nothing is written.
     *
     * @return void
     */
    #[Test]
    public function aMalformedFuneralDateWithACemeteryAbortsWithNothingWritten(): void
    {
        $tree = $this->tree();

        $this->expectException(MalformedDeathDateException::class);

        try {
            $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', 'Waldfriedhof', '2023-02-31', 'https://trauer.example/x');
        } finally {
            self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
            self::assertCount(0, iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true)));
        }
    }

    /**
     * A malformed funeral date WITHOUT a cemetery does not abort: no BURI is written, the funeral date is
     * irrelevant and never validated, and the DEAT writes exactly as in 2d-3a.
     *
     * @return void
     */
    #[Test]
    public function aMalformedFuneralDateWithoutACemeteryDoesNotAbort(): void
    {
        // No cemetery → no BURI → the funeral date is irrelevant and must NOT be validated/abort:
        // the DEAT still writes exactly as in 2d-3a.
        $tree      = $this->tree();
        $writeBack = $this->writer()->writeConfirm($this->person('I1', $tree), '2023-09-04', null, '2023-02-31', 'https://trauer.example/x');

        self::assertNull($writeBack->buriFactId);
        self::assertNotSame('', $writeBack->deatFactId);
        self::assertCount(1, iterator_to_array($this->person('I1', $tree)->facts(['DEAT'], false, null, true)));
    }

    /**
     * The atomic-write gate (#41): the fact ids RETURNED by writeConfirm — computed as md5 of the
     * exact fact gedcom written in the single updateRecord — must EQUAL the ids of the facts as
     * webtrees parsed and stored them (Fact::id() = md5 of the stored fact gedcom). This pins the
     * `md5(written) === md5(stored)` invariant the merged Revert depends on: a record-level
     * normalisation that altered a non-trailing fact (or a re-nesting) would break this equality and
     * Revert could no longer resolve the facts. If this assertion fails, the atomic design is invalid.
     *
     * @return void
     */
    #[Test]
    public function theReturnedFactIdsEqualTheStoredFactIds(): void
    {
        $tree = $this->tree();

        $writeBack = $this->writer()->writeConfirm(
            $this->person('I1', $tree),
            '2023-09-04',
            'Waldfriedhof Beispielstadt',
            '2023-09-10',
            'https://trauer.example/x'
        );

        self::assertNotNull($writeBack->buriFactId);

        // Re-fetch so the assertions read the committed records and their stored fact ids.
        $reloaded = $this->person('I1', $tree);

        $deatFacts = iterator_to_array($reloaded->facts(['DEAT'], false, null, true));
        $buriFacts = iterator_to_array($reloaded->facts(['BURI'], false, null, true));

        self::assertCount(1, $deatFacts);
        self::assertCount(1, $buriFacts);

        // The gate: md5(the fact gedcom I wrote) === the stored fact's id() for BOTH facts.
        self::assertSame($deatFacts[0]->id(), $writeBack->deatFactId, 'the returned DEAT id must equal the stored fact id (md5 written === md5 stored)');
        self::assertSame($buriFacts[0]->id(), $writeBack->buriFactId, 'the returned BURI id must equal the stored fact id (md5 written === md5 stored)');
    }

    /**
     * A literal `@` in a cemetery or the obituary URL must be escaped to `@@` in the stored GEDCOM value
     * (GEDCOM 5.5.1 + webtrees' AbstractElement::escape convention — a bare `@` starts an XREF pointer).
     * webtrees stores the createFact() string verbatim, so the writer must escape the value AND build its
     * capture substring from the SAME escaped value: the capture still locates the just-written BURI, and
     * the stored gedcom carries the escaped `2 PLAC … @@ …` / `3 PAGE …@@…` forms.
     *
     * @return void
     */
    #[Test]
    public function escapesAnAtSignInTheCemeteryAndUrl(): void
    {
        $tree = $this->tree();

        $writeBack = $this->writer()->writeConfirm(
            $this->person('I1', $tree),
            '2023-09-04',
            'Friedhof @ St. Anna',
            '2023-09-10',
            'https://user@host.example/n@1'
        );

        // Capture succeeded — the writer located the just-written BURI by its ESCAPED substring.
        self::assertNotNull($writeBack->buriFactId);

        $buri = iterator_to_array($this->person('I1', $tree)->facts(['BURI'], false, null, true));

        self::assertCount(1, $buri);

        // The stored gedcom carries the ESCAPED forms, proving webtrees stored the escaped value verbatim.
        $gedcom = $buri[0]->gedcom();
        self::assertStringContainsString('2 PLAC Friedhof @@ St. Anna', $gedcom);
        self::assertStringContainsString('3 PAGE https://user@@host.example/n@@1', $gedcom);
    }
}
