<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Domain\NegativeMemoryEntry;
use MagicSunday\ObituaryMatcher\Domain\SearchSignature;
use MagicSunday\ObituaryMatcher\Matching\FileNegativeMemoryStore;
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
 * Tests the file-backed negative-memory store: a recorded person's miss reads back intact, an
 * unrecorded person reads as null, a re-record overwrites, and a corrupt/oversize document degrades to
 * null (read) or is refused (write) rather than breaking the enqueue path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileNegativeMemoryStore::class)]
#[UsesClass(NegativeMemoryEntry::class)]
#[UsesClass(SearchSignature::class)]
#[UsesClass(AtomicFile::class)]
final class FileNegativeMemoryStoreTest extends TempDirTestCase
{
    /**
     * A recorded person's miss reads back with its signature and timestamp intact.
     *
     * @return void
     */
    #[Test]
    public function recordsAndReadsBackAPersonsMiss(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');
        $entry = new NegativeMemoryEntry(new SearchSignature('sig-abc'), 1_700_000_000);

        $store->record('I1', $entry);

        $read = $store->find('I1');
        self::assertInstanceOf(NegativeMemoryEntry::class, $read);
        self::assertSame('sig-abc', $read->signature->hash);
        self::assertSame(1_700_000_000, $read->recordedAt);
    }

    /**
     * An unrecorded person reads back as null, not an error.
     *
     * @return void
     */
    #[Test]
    public function readsAnUnrecordedPersonAsNull(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        self::assertNull($store->find('I999'));
    }

    /**
     * A second record for the same person overwrites the first (last-write-wins), matching a re-drain.
     *
     * @return void
     */
    #[Test]
    public function reRecordingAPersonOverwritesTheEarlierMiss(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->record('I1', new NegativeMemoryEntry(new SearchSignature('old'), 1_000));
        $store->record('I1', new NegativeMemoryEntry(new SearchSignature('new'), 2_000));

        $read = $store->find('I1');
        self::assertInstanceOf(NegativeMemoryEntry::class, $read);
        self::assertSame('new', $read->signature->hash);
        self::assertSame(2_000, $read->recordedAt);
    }

    /**
     * A corrupt (non-JSON) document reads back as null rather than throwing: the store honours its
     * fail-soft contract so a truncated file never breaks the enqueue path.
     *
     * @return void
     */
    #[Test]
    public function readsACorruptFileAsNull(): void
    {
        $dir = $this->tmp . '/negative-memory';
        AtomicFile::ensureDirectory($dir);
        file_put_contents(sprintf('%s/%s.json', $dir, hash('sha256', 'I1')), '{ not json');

        self::assertNull((new FileNegativeMemoryStore($dir))->find('I1'));
    }

    /**
     * A structurally-corrupt memory row (an unknown-shaped value the entry cannot rebuild) reads back as
     * null, not fatal.
     *
     * @return void
     */
    #[Test]
    public function readsACorruptRowAsNull(): void
    {
        $dir = $this->tmp . '/negative-memory';
        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $dir, hash('sha256', 'I1')),
            ['personId' => 'I1', 'memory' => ['signature' => '', 'recordedAt' => 'nope']]
        );

        self::assertNull((new FileNegativeMemoryStore($dir))->find('I1'));
    }

    /**
     * An oversize document is refused at write time (a loud failure) instead of persisting a file a
     * capped reader could never read back.
     *
     * @return void
     */
    #[Test]
    public function refusesToWriteAnOversizeDocument(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');
        $entry = new NegativeMemoryEntry(new SearchSignature(str_repeat('x', 70_000)), 1_000);

        $this->expectException(RuntimeException::class);

        $store->record('I1', $entry);
    }
}
