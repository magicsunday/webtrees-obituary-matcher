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
use function sprintf;
use function str_repeat;

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
}
