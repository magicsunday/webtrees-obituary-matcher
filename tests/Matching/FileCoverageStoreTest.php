<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use MagicSunday\ObituaryMatcher\Matching\FileCoverageStore;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Support\CoverageMerge;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function hash;
use function iterator_to_array;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_repeat;
use function symlink;

/**
 * Tests the file-backed coverage store, now keyed per (person × finder) (§5.2c): each finder's coverage
 * is recorded and read back independently, one finder's coverage never clobbers another's, a read UNIONS
 * every finder's coverage into one row per portal (a portal reads as covered if ANY finder covered it),
 * a re-record for the same finder overwrites, and a corrupt/oversize document degrades to empty (read) or
 * is refused (write) rather than breaking the person's tab render.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileCoverageStore::class)]
#[UsesClass(PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
#[UsesClass(CoverageMerge::class)]
#[UsesClass(AtomicFile::class)]
final class FileCoverageStoreTest extends TempDirTestCase
{
    /**
     * A recorded finder's coverage reads back, one row per portal, ordered by portal id.
     *
     * @return void
     */
    #[Test]
    public function recordsAndReadsBackAFindersCoverage(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', 'https://finder.a', [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 2, null),
            new PortalCoverage('freiepresse', CoverageStatus::Failed, null, 'timeout'),
        ]);

        $coverage = $store->findByPerson('I1');

        // Ordered by portal id: freiepresse before trauer_anzeigen.
        self::assertCount(2, $coverage);
        self::assertSame('freiepresse', $coverage[0]->portal);
        self::assertSame(CoverageStatus::Failed, $coverage[0]->status);
        self::assertSame('trauer_anzeigen', $coverage[1]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[1]->status);
        self::assertSame(2, $coverage[1]->noticeCount);
    }

    /**
     * An unrecorded person reads back as an empty list, not an error.
     *
     * @return void
     */
    #[Test]
    public function readsAnUnrecordedPersonAsEmpty(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        self::assertSame([], $store->findByPerson('I999'));
    }

    /**
     * A portal one finder failed and another finder covered OK reads back as `ok` — the §5.2c union: a
     * single finder's portal failure must not mask another finder's successful coverage of it. This is the
     * core multi-finder fix and the store's wiring of {@see CoverageMerge}.
     *
     * @return void
     */
    #[Test]
    public function unionsOkOverFailedAcrossFinders(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', 'https://finder.a', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, 'timeout')]);
        $store->record('I1', 'https://finder.b', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 1, null)]);

        $coverage = $store->findByPerson('I1');

        self::assertCount(1, $coverage);
        self::assertSame('trauer_anzeigen', $coverage[0]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[0]->status);
    }

    /**
     * Distinct portals from different finders all survive the union — one finder's coverage never drops
     * another's.
     *
     * @return void
     */
    #[Test]
    public function oneFindersCoverageDoesNotClobberAnother(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', 'https://finder.a', [new PortalCoverage('alpha', CoverageStatus::Ok, 1, null)]);
        $store->record('I1', 'https://finder.b', [new PortalCoverage('zebra', CoverageStatus::Ok, 1, null)]);

        $coverage = $store->findByPerson('I1');

        self::assertCount(2, $coverage);
        self::assertSame('alpha', $coverage[0]->portal);
        self::assertSame('zebra', $coverage[1]->portal);
    }

    /**
     * A second record for the SAME finder overwrites that finder's earlier coverage (last-write-wins per
     * finder, matching a re-drain), while leaving other finders' coverage untouched.
     *
     * @return void
     */
    #[Test]
    public function reRecordingTheSameFinderOverwritesOnlyThatFindersCoverage(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', 'https://finder.a', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, null)]);
        $store->record('I1', 'https://finder.b', [new PortalCoverage('freiepresse', CoverageStatus::Ok, 1, null)]);
        $store->record('I1', 'https://finder.a', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 1, null)]);

        $coverage = $store->findByPerson('I1');

        self::assertCount(2, $coverage);
        self::assertSame('freiepresse', $coverage[0]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[0]->status);
        self::assertSame('trauer_anzeigen', $coverage[1]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[1]->status);
    }

    /**
     * A stored finder document whose coverage mixes one valid row with one corrupt row surfaces exactly
     * the valid entry: the corrupt row (an unknown status the store's own {@see PortalCoverage::fromArray}
     * cannot rebuild) is dropped, not fatal.
     *
     * @return void
     */
    #[Test]
    public function dropsACorruptRowKeepingTheValidOne(): void
    {
        $dir  = $this->tmp . '/coverage';
        $path = sprintf('%s/%s/%s.json', $dir, hash('sha256', 'I1'), hash('sha256', 'https://finder.a'));
        AtomicFile::ensureDirectory(dirname($path));
        AtomicFile::writeJson(
            $path,
            [
                'personId' => 'I1',
                'finderId' => 'https://finder.a',
                'coverage' => [
                    (new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 3, null))->toArray(),
                    ['portal' => 'freiepresse', 'status' => 'bogus', 'noticeCount' => null, 'message' => null],
                ],
            ]
        );

        $coverage = (new FileCoverageStore($dir))->findByPerson('I1');

        self::assertCount(1, $coverage);
        self::assertSame('trauer_anzeigen', $coverage[0]->portal);
        self::assertSame(CoverageStatus::Ok, $coverage[0]->status);
    }

    /**
     * A corrupt (non-JSON) finder document reads back as an empty list rather than throwing: the store
     * honours its fail-soft contract so a truncated or malformed file never breaks the person's tab render.
     *
     * @return void
     */
    #[Test]
    public function readsACorruptFileAsEmpty(): void
    {
        $dir  = $this->tmp . '/coverage';
        $path = sprintf('%s/%s/%s.json', $dir, hash('sha256', 'I1'), hash('sha256', 'https://finder.a'));
        AtomicFile::ensureDirectory(dirname($path));
        file_put_contents($path, '{ not json');

        self::assertSame([], (new FileCoverageStore($dir))->findByPerson('I1'));
    }

    /**
     * A legacy pre-§5.2c single-document coverage file (written before the per-finder layout as
     * `<dir>/<sha(personId)>.json`) reads back as empty rather than throwing: coverage self-heals on the
     * next drain, when each finder re-records into its own per-finder file.
     *
     * @return void
     */
    #[Test]
    public function readsALegacySingleDocumentAsEmpty(): void
    {
        $dir = $this->tmp . '/coverage';
        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $dir, hash('sha256', 'I1')),
            ['personId' => 'I1', 'coverage' => [(new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 1, null))->toArray()]]
        );

        self::assertSame([], (new FileCoverageStore($dir))->findByPerson('I1'));
    }

    /**
     * An oversize coverage document is refused at write time (a loud failure in the drain path) instead
     * of persisting a file a capped reader could never read back — the write cap mirrors the read cap so
     * a hostile finder cannot orphan a file that would break every later render for that person.
     *
     * @return void
     */
    #[Test]
    public function refusesToWriteAnOversizeCoverageDocument(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        // A single portal carrying a message beyond the 1 MiB store ceiling: the encoded document
        // exceeds the cap, so the write is rejected before it can orphan an unreadable file.
        $oversize = [new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, str_repeat('x', 1_100_000))];

        $this->expectException(RuntimeException::class);

        $store->record('I1', 'https://finder.a', $oversize);
    }

    /**
     * each() enumerates every searched person, keyed by personId, with the coverage UNIONED across that
     * person's finders (the same merge findByPerson applies) — the tree-wide read the worklist retry
     * surface consumes.
     *
     * @return void
     */
    #[Test]
    public function eachYieldsEverySearchedPersonWithMergedCoverage(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', 'https://finder.a', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, 'timeout')]);
        $store->record('I1', 'https://finder.b', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 1, null)]);
        $store->record('I2', 'https://finder.a', [new PortalCoverage('freiepresse', CoverageStatus::Failed, null, null)]);

        $byPerson = iterator_to_array($store->each());

        self::assertCount(2, $byPerson);
        self::assertArrayHasKey('I1', $byPerson);
        self::assertArrayHasKey('I2', $byPerson);

        // I1's portal was failed by finder.a and ok by finder.b → union reads ok (one row).
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('trauer_anzeigen', $byPerson['I1'][0]->portal);
        self::assertSame(CoverageStatus::Ok, $byPerson['I1'][0]->status);

        // I2 has a single failed portal.
        self::assertCount(1, $byPerson['I2']);
        self::assertSame(CoverageStatus::Failed, $byPerson['I2'][0]->status);
    }

    /**
     * each() ignores an in-flight atomic temp file (`<hash>.json.tmp.<uniqid>`, whose extension is the
     * uniqid, not "json") so a concurrent record mid-write never yields a half-written person.
     *
     * @return void
     */
    #[Test]
    public function eachSkipsInFlightTempFiles(): void
    {
        $dir = $this->tmp . '/coverage';
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        file_put_contents(sprintf('%s/%s.json.tmp.abc123', $subdir, hash('sha256', 'https://finder.b')), '{ half written');

        $byPerson = iterator_to_array((new FileCoverageStore($dir))->each());

        self::assertCount(1, $byPerson);
        self::assertArrayHasKey('I1', $byPerson);
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('alpha', $byPerson['I1'][0]->portal);
    }

    /**
     * each() over an absent store directory yields nothing (the store was never written to) rather than
     * throwing — the fail-soft render-scan contract for a tree that has never been searched.
     *
     * @return void
     */
    #[Test]
    public function eachYieldsNothingForAnAbsentStoreDir(): void
    {
        self::assertSame([], iterator_to_array((new FileCoverageStore($this->tmp . '/never'))->each()));
    }

    /**
     * A document carrying no personId is dropped by BOTH reads: each() cannot address it tree-wide, and
     * findByPerson requires a document's stored personId to equal the requested id, so an id-less
     * (legacy/corrupt) document is not attributed to anyone. Keeping both reads on the same strict rule is
     * what makes them agree under corruption.
     *
     * @return void
     */
    #[Test]
    public function aDocumentWithoutPersonIdIsDroppedByBothEachAndFindByPerson(): void
    {
        $dir = $this->tmp . '/coverage';
        // A finder document carrying coverage but NO personId key (a legacy/corrupt shape); a null
        // bodyPersonId omits the key.
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), null);

        $store = new FileCoverageStore($dir);

        self::assertSame([], iterator_to_array($store->each()));
        self::assertSame([], $store->findByPerson('I1'));
    }

    /**
     * A document whose personId is PRESENT but invalid (an empty string here; a non-string normalises the
     * same way) is dropped by findByPerson exactly like an absent id — the tolerant path is for a matching
     * id only, never for a corrupt identity value. Guards the malformed-but-present half so findByPerson
     * and each() (which also drops it) cannot diverge.
     *
     * @return void
     */
    #[Test]
    public function findByPersonDropsADocumentWhosePersonIdIsPresentButInvalid(): void
    {
        $dir = $this->tmp . '/coverage';
        // Owner directory is I1's, but the document body carries an empty personId.
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), '');

        self::assertSame([], (new FileCoverageStore($dir))->findByPerson('I1'));
    }

    /**
     * each() skips a corrupt (non-JSON) document in a person's directory while still unioning a sibling
     * valid document — the fail-soft posture must hold in the tree-wide scan, not just the per-person read.
     *
     * @return void
     */
    #[Test]
    public function eachSkipsACorruptDocumentWhileUnioningASiblingValidOne(): void
    {
        $dir = $this->tmp . '/coverage';
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        file_put_contents(sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.b')), '{ not json');

        $byPerson = iterator_to_array((new FileCoverageStore($dir))->each());

        self::assertCount(1, $byPerson);
        self::assertSame('alpha', $byPerson['I1'][0]->portal);
    }

    /**
     * each() validates the personId of EACH document against its containing directory (dir === sha256(id)):
     * a misplaced document (correct JSON, but a personId that hashes to a different directory) is dropped
     * and its coverage is NOT unioned into the resident person — regardless of iterator order — so a corrupt
     * or misplaced record can never misattribute one person's coverage to another (whom the handler would
     * then link). The valid resident document still yields the person with only its own coverage.
     *
     * @return void
     */
    #[Test]
    public function eachValidatesPersonIdPerDocumentAgainstTheDirectory(): void
    {
        $dir = $this->tmp . '/coverage';
        // Resident, valid: personId I1 in I1's directory.
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');
        // Misplaced: a document whose body personId is I2, sitting in I1's directory.
        $this->writeDoc($dir, 'I1', 'https://finder.b', new PortalCoverage('zebra', CoverageStatus::Ok, 1, null), 'I2');

        $byPerson = iterator_to_array((new FileCoverageStore($dir))->each());

        self::assertCount(1, $byPerson);
        self::assertArrayHasKey('I1', $byPerson);
        self::assertArrayNotHasKey('I2', $byPerson);
        // Only the resident I1 document's coverage — the misplaced I2 document is dropped, not unioned.
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('alpha', $byPerson['I1'][0]->portal);
    }

    /**
     * findByPerson also drops a misplaced document whose stored personId belongs to ANOTHER person (it
     * hashed into the requested person's directory by corruption), while still unioning the requested
     * person's own document — so the per-person read and the tree-wide each() agree under corruption. A
     * null-personId document stays tolerated (covered separately); this pins the MISMATCHED half.
     *
     * @return void
     */
    #[Test]
    public function findByPersonDropsAMisplacedForeignPersonIdDocument(): void
    {
        $dir = $this->tmp . '/coverage';
        // I1's own document (union it) plus a misplaced I2-bodied document in I1's directory (drop it).
        $this->writeDoc($dir, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');
        $this->writeDoc($dir, 'I1', 'https://finder.b', new PortalCoverage('zebra', CoverageStatus::Ok, 1, null), 'I2');

        $coverage = (new FileCoverageStore($dir))->findByPerson('I1');

        // Only I1's own coverage survives — the misplaced I2 document is not attributed to I1.
        self::assertCount(1, $coverage);
        self::assertSame('alpha', $coverage[0]->portal);
    }

    /**
     * A directory whose ONLY document is misplaced (its personId hashes elsewhere) yields nothing from
     * each() — there is no valid resident to attribute the coverage to.
     *
     * @return void
     */
    #[Test]
    public function eachYieldsNothingForADirectoryOfOnlyMisplacedDocuments(): void
    {
        $dir = $this->tmp . '/coverage';
        // A document whose body personId is I3, sitting in I2's directory.
        $this->writeDoc($dir, 'I2', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I3');

        self::assertSame([], iterator_to_array((new FileCoverageStore($dir))->each()));
    }

    /**
     * each() skips a legacy pre-§5.2c root-level document (`<dir>/<sha(personId)>.json`, a FILE at the
     * store root rather than a per-person sub-directory): it is not a directory, so the scan skips it
     * before the hash guard and it is never surfaced tree-wide — matching findByPerson's legacy tolerance.
     *
     * @return void
     */
    #[Test]
    public function eachSkipsALegacyRootLevelDocument(): void
    {
        $dir = $this->tmp . '/coverage';
        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $dir, hash('sha256', 'I1')),
            ['personId' => 'I1', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );

        self::assertSame([], iterator_to_array((new FileCoverageStore($dir))->each()));
    }

    /**
     * each() does not descend a symlinked sub-directory even when isDir() reports true through the link,
     * so the tree-wide scan can never follow a symlink out of the store and read coverage documents
     * elsewhere on disk (mirrors FileMatchStore::allRows). Skipped where the platform cannot create a
     * symlink.
     *
     * @return void
     */
    #[Test]
    public function eachDoesNotDescendASymlinkedSubdirectory(): void
    {
        $dir = $this->symlinkedPersonStore();

        self::assertSame([], iterator_to_array((new FileCoverageStore($dir))->each()));
    }

    /**
     * findByPerson also refuses a symlinked person directory: if the person's hashed directory is replaced
     * with a symlink to a directory holding a VALID matching-personId document, findByPerson returns []
     * rather than following the link out of the store — mirroring each()'s symlinked-subdirectory policy so
     * the per-person read and the tree-wide read agree under a corrupted store layout. Skipped where the
     * platform cannot create a symlink.
     *
     * @return void
     */
    #[Test]
    public function findByPersonRefusesASymlinkedPersonDirectory(): void
    {
        $dir = $this->symlinkedPersonStore();

        self::assertSame([], (new FileCoverageStore($dir))->findByPerson('I1'));
    }

    /**
     * Both reads reject a symlinked coverage DOCUMENT, not just a symlinked directory: a person's real
     * hashed directory can hold a finder document that is itself a symlink to a valid, matching-personId
     * document placed OUTSIDE the store. readCoverageDoc refuses it because it reads through
     * {@see AtomicFile::readJsonCapped}, which rejects a symlink (its is_link guard) before ever opening
     * the path — so a corrupted store layout cannot leak an arbitrary file through a symlinked document.
     * This pins that store-level reliance on the AtomicFile symlink guard so a future refactor of the read
     * path cannot silently drop it. Skipped where the platform cannot create a symlink.
     *
     * @return void
     */
    #[Test]
    public function bothReadsRejectASymlinkedCoverageDocument(): void
    {
        $dir = $this->symlinkedDocumentStore();

        self::assertSame([], iterator_to_array((new FileCoverageStore($dir))->each()));
        self::assertSame([], (new FileCoverageStore($dir))->findByPerson('I1'));
    }

    /**
     * Builds the corrupted-layout fixture both symlink guards must refuse: a VALID, matching-personId
     * document for I1 placed OUTSIDE the store, with I1's hashed directory inside the store replaced by a
     * symlink to it (so a reader that followed the link would leak the outside document). Skips the test on
     * a platform that cannot create a symlink. Returns the store directory.
     *
     * @return string The store directory whose I1 sub-directory is a symlink pointing outside.
     */
    private function symlinkedPersonStore(): string
    {
        $dir     = $this->tmp . '/coverage';
        $outside = $this->tmp . '/outside';
        AtomicFile::ensureDirectory($dir);
        $this->writeDoc($outside, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');

        $link = sprintf('%s/%s', $dir, hash('sha256', 'I1'));

        $this->createSymlinkOrSkip(sprintf('%s/%s', $outside, hash('sha256', 'I1')), $link);

        return $dir;
    }

    /**
     * Builds a store whose I1 person directory is REAL but holds a finder document that is a symlink to a
     * valid, matching-personId document placed OUTSIDE the store — the file-level analogue of
     * {@see self::symlinkedPersonStore()}. A reader that followed the link would leak the outside document.
     * Skips the test on a platform that cannot create a symlink. Returns the store directory.
     *
     * @return string The store directory whose I1 finder document is a symlink pointing outside.
     */
    private function symlinkedDocumentStore(): string
    {
        $dir     = $this->tmp . '/coverage';
        $outside = $this->tmp . '/outside';

        $this->writeDoc($outside, 'I1', 'https://finder.a', new PortalCoverage('alpha', CoverageStatus::Ok, 1, null), 'I1');

        $personDir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($personDir);

        $target = sprintf('%s/%s/%s.json', $outside, hash('sha256', 'I1'), hash('sha256', 'https://finder.a'));
        $link   = sprintf('%s/%s.json', $personDir, hash('sha256', 'https://finder.a'));

        $this->createSymlinkOrSkip($target, $link);

        return $dir;
    }

    /**
     * Creates a symlink from $link to $target, skipping the test on a platform that cannot create one. The
     * probe avoids the banned @-suppression: a scoped handler swallows the E_WARNING symlink() emits when
     * the platform cannot create a symlink, so the return value drives the skip.
     *
     * @param string $target The path the symlink points to.
     * @param string $link   The symlink path to create.
     *
     * @return void
     */
    private function createSymlinkOrSkip(string $target, string $link): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $linked = symlink($target, $link);
        } finally {
            restore_error_handler();
        }

        if (!$linked) {
            self::markTestSkipped('The platform does not support creating symlinks.');
        }
    }

    /**
     * Writes one finder coverage document into a person's directory under the given store dir. $ownerPerson
     * selects the sha256(id) sub-directory the document lands in; $bodyPersonId is the personId written
     * INSIDE the document — equal to $ownerPerson for a well-placed record, a DIFFERENT id for a misplaced
     * one, or the empty string to omit the personId key entirely (a legacy/corrupt shape). Used by the
     * each() tests so their identical seeding never duplicates.
     *
     * @param string         $dir          The store directory.
     * @param string         $ownerPerson  The person whose sub-directory the document lands in.
     * @param string         $finderId     The finder the document belongs to.
     * @param PortalCoverage $coverage     The document's single coverage row.
     * @param string|null    $bodyPersonId The personId written into the body: null omits the key entirely
     *                                     (a legacy/id-less shape); an empty string writes an explicit,
     *                                     invalid personId (a corrupt shape).
     *
     * @return void
     */
    private function writeDoc(string $dir, string $ownerPerson, string $finderId, PortalCoverage $coverage, ?string $bodyPersonId): void
    {
        $subdir = sprintf('%s/%s', $dir, hash('sha256', $ownerPerson));
        AtomicFile::ensureDirectory($subdir);

        $body = ['finderId' => $finderId, 'coverage' => [$coverage->toArray()]];

        if ($bodyPersonId !== null) {
            $body['personId'] = $bodyPersonId;
        }

        AtomicFile::writeJson(sprintf('%s/%s.json', $subdir, hash('sha256', $finderId)), $body);
    }
}
