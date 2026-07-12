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
        $dir    = $this->tmp . '/coverage';
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($subdir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.a')),
            ['personId' => 'I1', 'finderId' => 'https://finder.a', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );
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
     * A document carrying no personId is dropped by each() (it cannot be addressed tree-wide), but
     * findByPerson STILL tolerantly unions its coverage — the per-person path is handed the id and never
     * reads it from content, so it must not regress.
     *
     * @return void
     */
    #[Test]
    public function eachDropsADocumentWithoutPersonIdWhileFindByPersonStillUnionsIt(): void
    {
        $dir    = $this->tmp . '/coverage';
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($subdir);
        // A finder document carrying coverage but NO personId key (a legacy/corrupt shape).
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.a')),
            ['coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );

        $store = new FileCoverageStore($dir);

        // each() cannot attribute it to a person → not yielded.
        self::assertSame([], iterator_to_array($store->each()));

        // findByPerson('I1') is asked for I1 and scans I1's dir → still unions the coverage.
        $coverage = $store->findByPerson('I1');
        self::assertCount(1, $coverage);
        self::assertSame('alpha', $coverage[0]->portal);
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
        $dir    = $this->tmp . '/coverage';
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($subdir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.a')),
            ['personId' => 'I1', 'finderId' => 'https://finder.a', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );
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
        $dir    = $this->tmp . '/coverage';
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($subdir);
        // Resident, valid: personId I1 in I1's directory.
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.a')),
            ['personId' => 'I1', 'finderId' => 'https://finder.a', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );
        // Misplaced: a document whose personId is I2, sitting in I1's directory.
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.b')),
            ['personId' => 'I2', 'finderId' => 'https://finder.b', 'coverage' => [(new PortalCoverage('zebra', CoverageStatus::Ok, 1, null))->toArray()]]
        );

        $byPerson = iterator_to_array((new FileCoverageStore($dir))->each());

        self::assertCount(1, $byPerson);
        self::assertArrayHasKey('I1', $byPerson);
        self::assertArrayNotHasKey('I2', $byPerson);
        // Only the resident I1 document's coverage — the misplaced I2 document is dropped, not unioned.
        self::assertCount(1, $byPerson['I1']);
        self::assertSame('alpha', $byPerson['I1'][0]->portal);
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
        $dir    = $this->tmp . '/coverage';
        $subdir = sprintf('%s/%s', $dir, hash('sha256', 'I2'));
        AtomicFile::ensureDirectory($subdir);
        // A document claiming personId I3, sitting in I2's directory.
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $subdir, hash('sha256', 'https://finder.a')),
            ['personId' => 'I3', 'finderId' => 'https://finder.a', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );

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
        $dir     = $this->tmp . '/coverage';
        $outside = $this->tmp . '/outside';
        AtomicFile::ensureDirectory($dir);
        // A real, VALID coverage document outside the store, in a directory named as if it were person I1's
        // (so a symlink from the store to it would otherwise pass the hash guard and leak).
        $outsidePerson = sprintf('%s/%s', $outside, hash('sha256', 'I1'));
        AtomicFile::ensureDirectory($outsidePerson);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $outsidePerson, hash('sha256', 'https://finder.a')),
            ['personId' => 'I1', 'finderId' => 'https://finder.a', 'coverage' => [(new PortalCoverage('alpha', CoverageStatus::Ok, 1, null))->toArray()]]
        );

        $link = sprintf('%s/%s', $dir, hash('sha256', 'I1'));

        if (!@symlink($outsidePerson, $link)) {
            self::markTestSkipped('The platform does not support creating symlinks.');
        }

        self::assertSame([], iterator_to_array((new FileCoverageStore($dir))->each()));
    }
}
