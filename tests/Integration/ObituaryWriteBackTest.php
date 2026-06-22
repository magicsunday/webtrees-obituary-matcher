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
use Fisharebest\Webtrees\Individual;
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

/**
 * Integration tests for {@see ObituaryWriteBack::writeDeath()} — the actual sourced DEAT write over a
 * real tree: precondition rejections, the live re-check race guard, the dated+sourced DEAT fact, and
 * the deatFactId capture round-trip.
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
     * @return Tree The freshly-imported fixture tree with I1–I6.
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
        );
    }

    /**
     * Resolve an individual, asserting it exists so PHPStan narrows away the null and the test fails
     * loudly on a broken fixture rather than a later type error.
     *
     * @param string $xref The individual XREF.
     * @param Tree   $tree The fixture tree.
     *
     * @return Individual The resolved individual.
     */
    private function person(string $xref, Tree $tree): Individual
    {
        $individual = $this->individual($xref, $tree);

        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
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
     * writeDeath writes a dated, sourced DEAT and returns a WriteBack whose deatFactId resolves the
     * just-written fact (the capture round-trip), with the reserved fields at their 2d-3a values.
     *
     * @return void
     */
    #[Test]
    public function writesADatedSourcedDeatAndReturnsTheWriteBack(): void
    {
        $tree = $this->tree();
        $i1   = $this->person('I1', $tree);

        $writeBack = $this->writer()->writeDeath($i1, '2023-09-04', 'https://trauer.example/x');

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
            "1 DEAT\n2 DATE 04 SEP 2023\n2 SOUR @%s@\n3 PAGE https://trauer.example/x\n3 DATA\n4 DATE %s",
            $writeBack->sourceXref,
            GedcomDateConverter::toGedcom(date('Y-m-d'))
        );

        self::assertSame($expectedGedcom, $gedcom);

        // The capture round-trip — the riskiest logic. The returned id must be the id of the DEAT fact
        // resolved by RE-FETCHING the individual after the write (Fact::id() is the content hash of the
        // stored fact), proving writeDeath captured it from the live record, not from a value it could
        // have synthesised before the write. assertNotNull alone would be insufficient. The competing-DEAT
        // selection in captureDeatFactId() is exercised by leavesABareDeatUntouchedAndAddsADatedDeat().
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

        $first  = $this->writer()->writeDeath($i1, '2023-09-04', 'https://trauer.example/x');
        $second = $this->writer()->writeDeath($i2, '2024-01-15', 'https://www.trauer.example/y?utm=z');

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
            $this->writer()->writeDeath($i3, '2023-09-04', 'https://trauer.example/x');
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
            $this->writer()->writeDeath($i4, '2023-09-04', 'https://trauer.example/x');
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
            $this->writer()->writeDeath($i6, '2023-09-04', 'https://trauer.example/x');
            self::fail('expected a DeathDateAlreadyPresentException');
        } catch (DeathDateAlreadyPresentException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I6', $tree), 'no DEAT fact may be written');
    }

    /**
     * A bare DEAT (no DATE) does NOT count as a death date: the write succeeds, adding a SECOND, dated
     * DEAT, and the original bare DEAT survives byte-unchanged. This is also the competing-DEAT case
     * that exercises captureDeatFactId's substring selection — with two DEAT facts present, the returned
     * deatFactId must resolve the NEW sourced obituary fact, not the pre-existing bare one.
     *
     * @return void
     */
    #[Test]
    public function leavesABareDeatUntouchedAndAddsADatedDeat(): void
    {
        $tree = $this->tree();
        $i5   = $this->person('I5', $tree);

        $writeBack = $this->writer()->writeDeath($i5, '2023-09-04', 'https://trauer.example/x');

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
        self::assertStringContainsString('2 DATE 04 SEP 2023', $dated->gedcom());
        self::assertStringContainsString('2 SOUR @' . $writeBack->sourceXref . '@', $dated->gedcom());
        self::assertStringContainsString('3 PAGE https://trauer.example/x', $dated->gedcom());

        // captureDeatFactId selected the right fact among two DEATs: the returned id resolves the dated
        // obituary fact, never the bare one.
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
            $this->writer()->writeDeath($i1, '2023-09-04', $url);
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
            $this->writer()->writeDeath($i1, '2023-02-31', 'https://trauer.example/x');
            self::fail('expected a MalformedDeathDateException');
        } catch (MalformedDeathDateException) {
            // Expected.
        }

        self::assertSame($before, $this->deatCount('I1', $tree), 'no DEAT fact may be written on a malformed date');
    }
}
