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
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function file_put_contents;
use function hash;
use function sprintf;
use function str_repeat;

/**
 * Tests the file-backed coverage store: a recorded person's coverage reads back intact, an unrecorded
 * person reads as empty, and a second record for the same person overwrites the first.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileCoverageStore::class)]
#[UsesClass(PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
#[UsesClass(AtomicFile::class)]
final class FileCoverageStoreTest extends TempDirTestCase
{
    /**
     * A recorded person's coverage reads back with every field intact.
     *
     * @return void
     */
    #[Test]
    public function recordsAndReadsBackAPersonsCoverage(): void
    {
        $store    = new FileCoverageStore($this->tmp . '/coverage');
        $coverage = [
            new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 2, null),
            new PortalCoverage('freiepresse', CoverageStatus::Failed, null, 'timeout'),
        ];

        $store->record('I1', $coverage);

        self::assertEquals($coverage, $store->findByPerson('I1'));
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
     * A second record for the same person overwrites the first (last-write-wins), matching a re-drain.
     *
     * @return void
     */
    #[Test]
    public function reRecordingAPersonOverwritesTheEarlierCoverage(): void
    {
        $store = new FileCoverageStore($this->tmp . '/coverage');

        $store->record('I1', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Failed, null, null)]);
        $store->record('I1', [new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, 1, null)]);

        $coverage = $store->findByPerson('I1');

        self::assertCount(1, $coverage);
        self::assertSame(CoverageStatus::Ok, $coverage[0]->status);
    }

    /**
     * A stored document whose coverage mixes one valid row with one corrupt row surfaces exactly the
     * valid entry: the corrupt row (an unknown status the store's own {@see PortalCoverage::fromArray}
     * cannot rebuild) is dropped, not fatal.
     *
     * @return void
     */
    #[Test]
    public function dropsACorruptRowKeepingTheValidOne(): void
    {
        $dir = $this->tmp . '/coverage';
        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $dir, hash('sha256', 'I1')),
            [
                'personId' => 'I1',
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
     * A corrupt (non-JSON) document reads back as an empty list rather than throwing: the store honours
     * its fail-soft contract so a truncated or malformed file never breaks the person's tab render.
     *
     * @return void
     */
    #[Test]
    public function readsACorruptFileAsEmpty(): void
    {
        $dir = $this->tmp . '/coverage';
        AtomicFile::ensureDirectory($dir);
        file_put_contents(sprintf('%s/%s.json', $dir, hash('sha256', 'I1')), '{ not json');

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

        $store->record('I1', $oversize);
    }
}
