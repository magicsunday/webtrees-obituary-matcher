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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Webtrees\PortalSourceRepository;
use MagicSunday\ObituaryMatcher\Webtrees\WriteBackPreconditionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

/**
 * Integration tests for the pending-aware portal-source find-or-create.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryWriteBackSourceTest extends IntegrationTestCase
{
    /**
     * The writer with public test seams.
     *
     * @return ObituaryWriteBackSeam The writer exposing the protected source/host helpers.
     */
    private function writer(): ObituaryWriteBackSeam
    {
        return new ObituaryWriteBackSeam();
    }

    /**
     * Build a fresh tree the source helpers can write into. The base bootstrap already
     * logs in an administrator, who is implicitly an editor/moderator of every tree, so
     * the pending-changes visibility gate ({@see Auth::isEditor}) is satisfied.
     *
     * @return Tree The freshly-imported fixture tree.
     */
    private function tree(): Tree
    {
        return $this->importFixtureTree("0 @I1@ INDI\n1 NAME Otto /Vorbild/\n1 SEX M\n");
    }

    /**
     * Create an empty second tree so a cross-tree leakage test can prove a portal source in one
     * tree is never found when searching the other. {@see importFixtureTree()} always names its
     * tree "fixture", so this builds a distinctly-named empty tree alongside it.
     *
     * @return Tree The freshly-created, empty second tree.
     */
    private function secondTree(): Tree
    {
        return (new TreeService(new GedcomImportService()))->create('fixture2', 'fixture2');
    }

    /**
     * Make the logged-in user accept its edits immediately, so a created record lands in
     * the `sources` table rather than staying a pending change.
     *
     * @return void
     */
    private function loginManagerWithAutoAccept(): void
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '1');
    }

    /**
     * Make the logged-in user queue its edits as pending changes (the webtrees default),
     * so a created record is only visible through the pending-aware scan.
     *
     * @return void
     */
    private function loginManagerWithoutAutoAccept(): void
    {
        Auth::user()->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '0');
    }

    /**
     * Canonical host: lowercases and strips a leading www.
     *
     * @return void
     */
    #[Test]
    public function canonicalHostLowercasesAndStripsWww(): void
    {
        $w = $this->writer();
        self::assertSame('trauer.example', $w->host('https://Trauer.Example/a'));
        self::assertSame('trauer.example', $w->host('https://www.trauer.example/b?utm=x'));
    }

    /**
     * createPortalSource writes a 0 @@ SOUR and the returned record has a real xref + TITL/PUBL/REFN.
     *
     * The webtrees XrefFactory mints every record xref with the `X` prefix (it ignores the
     * record type), so an allocated source xref is `X<n>`, not `S<n>`.
     *
     * @return void
     */
    #[Test]
    public function createPortalSourceAllocatesXrefAndCarriesTheMarker(): void
    {
        $this->loginManagerWithAutoAccept(); // accept edits immediately for this assertion
        $tree   = $this->tree();
        $source = $this->writer()->create($tree, 'trauer.example');

        self::assertMatchesRegularExpression('/^X\d+$/', $source->xref());
        self::assertStringContainsString('1 TITL Death notice: trauer.example', $source->gedcom());
        self::assertStringContainsString('1 PUBL trauer.example', $source->gedcom());
        self::assertStringContainsString('1 REFN obituary-matcher:portal:trauer.example', $source->gedcom());
        self::assertStringNotContainsString('@@', $source->gedcom());
    }

    /**
     * acceptedSources filters at the database level when a REFN is supplied: only rows whose gedcom
     * carries the marker substring are loaded, so a large tree's unrelated accepted sources never enter
     * PHP memory. The unrelated accepted source is excluded by the SQL constraint; the portal source is
     * returned. Without the constraint the method would hand back every accepted source of the tree.
     *
     * @return void
     */
    #[Test]
    public function acceptedSourcesFiltersByRefnAtTheDatabaseLevel(): void
    {
        $this->loginManagerWithAutoAccept();
        $tree = $this->tree();

        $portal = $this->writer()->create($tree, 'trauer.example');
        $tree->createRecord("0 @@ SOUR\n1 TITL Unrelated"); // accepted, no portal REFN marker

        $rows  = (new PortalSourceRepository())->acceptedSources($tree, 'obituary-matcher:portal:trauer.example');
        $xrefs = array_map(static fn (array $row): string => $row['xref'], $rows);

        self::assertContains($portal->xref(), $xrefs, 'the portal source must be returned');
        self::assertCount(1, $rows, 'only the REFN-matching source is loaded; the unrelated one is filtered in SQL');
    }

    /**
     * findPortalSource returns null when none exists and the SAME pending source (pending-aware) when
     * auto-accept is OFF and the source is only a pending change.
     *
     * @return void
     */
    #[Test]
    public function findPortalSourceIsPendingAware(): void
    {
        $tree = $this->tree();
        $w    = $this->writer();

        $missing = $w->find($tree, 'trauer.example');
        self::assertNull($missing);

        // Seed an UNRELATED accepted source FIRST so the tree has ≥1 accepted source — the realistic
        // shape of every live tree.
        $this->loginManagerWithAutoAccept();
        $unrelated = $tree->createRecord("0 @@ SOUR\n1 TITL Unrelated"); // accepted, no REFN marker

        // ARM the stale-cache bug: resolve a record through SourceFactory so its request-scoped
        // pendingChanges cache is memoised NOW (it caches ALL pending rows once, none yet for the
        // about-to-be-created xref). This is exactly what a real request does while rendering the tree
        // before the confirm. A createPortalSource that re-resolved its fresh record via a bare
        // make($xref, $tree) would then read that stale cache, get null, and wrongly throw. The 3-arg
        // make($xref, $tree, $gedcom) is what makes the next line survive.
        Registry::sourceFactory()->make($unrelated->xref(), $tree);

        // auto-accept OFF → the created portal source is only a pending change, not in the sources table.
        $this->loginManagerWithoutAutoAccept();
        $created = $w->create($tree, 'trauer.example'); // must resolve despite the stale pendingChanges cache

        $found = $w->find($tree, 'trauer.example');
        self::assertNotNull($found, 'a pending portal source must be found, not duplicated');
        self::assertSame($created->xref(), $found->xref());
    }

    /**
     * Two different URLs that share a portal (after canonicalisation) resolve to the SAME source: the
     * canonicalHost → find/create round-trip is the per-portal dedup contract — one SOUR per host, not
     * per URL. A second confirm citing a `www.`/mixed-case variant must reuse, not duplicate.
     *
     * @return void
     */
    #[Test]
    public function findReusesTheSourceCreatedForAnEquivalentUrl(): void
    {
        $this->loginManagerWithAutoAccept();
        $tree = $this->tree();
        $w    = $this->writer();

        $created = $w->create($tree, $w->host('https://Trauer.Example/a-notice'));
        $found   = $w->find($tree, $w->host('https://www.trauer.example/another-notice?utm_source=x'));

        self::assertNotNull($found, 'an equivalent-URL variant must reuse the same per-portal source');
        self::assertSame($created->xref(), $found->xref());
    }

    /**
     * A pending portal source in one tree must never leak into a find() against a different tree: the
     * accepted/pending scans are both filtered to the searched tree, so cross-tree visibility stays
     * closed even for a not-yet-accepted source.
     *
     * @return void
     */
    #[Test]
    public function findDoesNotLeakAPortalSourceAcrossTrees(): void
    {
        $treeA = $this->tree();
        $treeB = $this->secondTree();
        $w     = $this->writer();

        // A pending source in tree A (auto-accept OFF keeps it out of the sources table).
        $this->loginManagerWithoutAutoAccept();
        $created = $w->create($treeA, 'trauer.example');

        $foundInA = $w->find($treeA, 'trauer.example');
        $foundInB = $w->find($treeB, 'trauer.example');

        self::assertNull($foundInB, 'a pending source must not leak into another tree');
        self::assertNotNull($foundInA, 'the source must be visible in its own tree');
        self::assertSame($created->xref(), $foundInA->xref());
    }

    /**
     * A duplicate-REFN tree (two accepted portal sources sharing the same host marker) resolves to a
     * single deterministic match without crashing — find-or-create never blows up on a pre-existing
     * duplicate. The accepted scan is ordered by `s_id`, so the lexically-first xref wins.
     *
     * @return void
     */
    #[Test]
    public function findPortalSourceReturnsADeterministicFirstOnDuplicateRefn(): void
    {
        $this->loginManagerWithAutoAccept();
        $tree = $this->tree();

        $first  = $this->writer()->create($tree, 'trauer.example');
        $second = $this->writer()->create($tree, 'trauer.example');

        self::assertNotSame($first->xref(), $second->xref());

        $found = $this->writer()->find($tree, 'trauer.example');
        self::assertNotNull($found);

        // The match must be one of the two created sources, and the choice must be deterministic
        // (a repeated find() returns the same xref) so a duplicate never flips the answer per request.
        self::assertContains($found->xref(), [$first->xref(), $second->xref()]);
        self::assertSame($found->xref(), $this->writer()->find($tree, 'trauer.example')?->xref());

        // The repository orders the accepted scan by s_id (a VARCHAR column → lexical), so the
        // lexically-smaller xref is the concrete winner; PHP min() on two strings agrees with that order.
        self::assertSame(min($first->xref(), $second->xref()), $found->xref());
    }

    /**
     * Appends a raw pending `change` row for the given xref, mirroring webtrees' append-only stack: the
     * `change` table never updates a prior row, it INSERTs a new one with the same xref, so each call
     * stacks a higher `change_id` for that xref. A pending DELETE is modelled as an empty `new_gedcom`.
     *
     * @param Tree   $tree      The tree the row belongs to.
     * @param string $xref      The record xref the change targets.
     * @param string $newGedcom The new gedcom blob (empty for a pending delete).
     *
     * @return void
     */
    private function appendPendingChange(Tree $tree, string $xref, string $newGedcom): void
    {
        DB::table('change')->insert([
            'gedcom_id'  => $tree->id(),
            'xref'       => $xref,
            'old_gedcom' => '',
            'new_gedcom' => $newGedcom,
            'status'     => 'pending',
            'user_id'    => Auth::id() ?? 0,
        ]);
    }

    /**
     * The portal-source REFN gedcom for a host, as createPortalSource would write it (the exact shape
     * pendingSources filters on).
     *
     * @param string $xref The source record xref.
     * @param string $host The canonical host.
     *
     * @return string The portal SOUR gedcom carrying the REFN marker.
     */
    private function portalSourceGedcom(string $xref, string $host): string
    {
        return "0 @{$xref}@ SOUR\n1 TITL Death notice: {$host}\n1 PUBL {$host}\n1 REFN obituary-matcher:portal:{$host}";
    }

    /**
     * A create-then-delete stack for one xref must NOT resurrect the deleted source: the webtrees
     * `change` table is append-only, so a pending CREATE row (carrying the REFN) followed by a pending
     * DELETE row (`new_gedcom = ''`) for the same xref means the record's authoritative pending state is
     * "deleted". The old code returned every REFN-matching row, so the stale create row still matched and
     * findPortalSource handed back a pending-deleted source; the per-xref fold to the latest change_id
     * drops it. find() must therefore return null (and a subsequent create makes a fresh, distinct one).
     *
     * @return void
     */
    #[Test]
    public function findPortalSourceDoesNotResurrectAPendingDeletedSource(): void
    {
        $this->loginManagerWithoutAutoAccept();
        $tree = $this->tree();
        $w    = $this->writer();

        // Stack a pending CREATE (carries the REFN) then a pending DELETE (empty new_gedcom) for X9.
        $this->appendPendingChange($tree, 'X9', $this->portalSourceGedcom('X9', 'trauer.example'));
        $this->appendPendingChange($tree, 'X9', '');

        // The latest pending state for X9 is "deleted", so the source must not be found.
        self::assertNull(
            $w->find($tree, 'trauer.example'),
            'a pending-deleted portal source must not be resurrected by the stale create row'
        );

        // A confirm then creates a brand-new source rather than reusing the deleted one.
        $created = $w->create($tree, 'trauer.example');
        self::assertNotSame('X9', $created->xref());
    }

    /**
     * A create-then-edit stack for one xref must resolve against the LATEST blob, not the stale create:
     * the append-only `change` table holds a CREATE row for `old.example` and a later EDIT row that
     * changed the host (and thus the REFN) to `new.example`, both for the same xref. The old code matched
     * the OLDEST blob first (change_id ASC), so a find() for `old.example` wrongly hit the superseded
     * row; the per-xref fold means only the latest `new.example` blob survives — `old.example` no longer
     * matches and `new.example` does.
     *
     * @return void
     */
    #[Test]
    public function findPortalSourceResolvesAgainstTheLatestBlobOnAStackedEdit(): void
    {
        $this->loginManagerWithoutAutoAccept();
        $tree = $this->tree();
        $w    = $this->writer();

        // Stack a pending CREATE for old.example then a pending EDIT moving X9 to new.example.
        $this->appendPendingChange($tree, 'X9', $this->portalSourceGedcom('X9', 'old.example'));
        $this->appendPendingChange($tree, 'X9', $this->portalSourceGedcom('X9', 'new.example'));

        // The superseded blob is gone: a find for the old host no longer hits the stale create row.
        self::assertNull(
            $w->find($tree, 'old.example'),
            'the superseded create blob must not match after the stacked edit'
        );

        // The latest blob is authoritative: the edited host resolves to the same pending record.
        $found = $w->find($tree, 'new.example');
        self::assertNotNull($found, 'the latest pending blob must resolve the edited host');
        self::assertSame('X9', $found->xref());
    }

    /**
     * A valid host is accepted: createPortalSource produces a clean source with no injected sub-record,
     * the positive control proving the guard discriminates rather than rejecting everything.
     *
     * @return void
     */
    #[Test]
    public function createPortalSourceAcceptsACleanHost(): void
    {
        $this->loginManagerWithAutoAccept();
        $tree = $this->tree();

        $source = $this->writer()->create($tree, 'trauer.example');

        self::assertStringContainsString('1 REFN obituary-matcher:portal:trauer.example', $source->gedcom());
        self::assertStringNotContainsString('1 NOTE', $source->gedcom());
    }

    /**
     * createPortalSource rejects a host carrying any control character before it can break out of the
     * GEDCOM REFN/TITL/PUBL line and inject arbitrary sub-records. One row per representative control
     * char (CR+LF, bare LF, NUL, DEL) pins the guard's character class.
     *
     * @param string $host A host carrying a control character.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('controlCharacterHostProvider')]
    public function createPortalSourceRejectsAHostWithAControlCharacter(string $host): void
    {
        $this->loginManagerWithAutoAccept();
        $tree = $this->tree();

        $this->expectException(WriteBackPreconditionException::class);

        $this->writer()->create($tree, $host);
    }

    /**
     * Representative control-character hosts the guard must reject.
     *
     * @return array<string, array{0: string}> The named control-char host rows.
     */
    public static function controlCharacterHostProvider(): array
    {
        return [
            'CR+LF line injection' => ["trauer.example\r\n1 NOTE injected"],
            'bare LF'              => ["trauer.example\n1 NOTE injected"],
            'NUL truncation'       => ["trauer.example\x00injected"],
            'DEL control'          => ["trauer.example\x7Finjected"],
        ];
    }
}
