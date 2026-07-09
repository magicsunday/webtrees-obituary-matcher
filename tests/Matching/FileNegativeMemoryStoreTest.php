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

use function dirname;
use function file_put_contents;
use function hash;
use function sprintf;
use function str_repeat;

/**
 * Tests the file-backed negative-memory store, now keyed per (person × finder) (§5.2f): each finder's
 * miss for a person is recorded and read back independently, one finder's miss never shadows another's,
 * a re-record for the same finder overwrites, clearing drops every finder's memory for the person, a
 * legacy single-finder document reads back as no memory (self-heal), and a corrupt/oversize document
 * degrades to null (read) or is refused (write) rather than breaking the enqueue path.
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
     * A recorded miss reads back with its signature and timestamp intact for the SAME finder.
     *
     * @return void
     */
    #[Test]
    public function recordsAndReadsBackAFindersMissForAPerson(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');
        $entry = new NegativeMemoryEntry(new SearchSignature('sig-abc'), 1_700_000_000);

        $store->record('I1', 'https://finder.a', $entry);

        $read = $store->find('I1', 'https://finder.a');
        self::assertInstanceOf(NegativeMemoryEntry::class, $read);
        self::assertSame('sig-abc', $read->signature->hash);
        self::assertSame(1_700_000_000, $read->recordedAt);
    }

    /**
     * One finder's recorded miss does not shadow another finder's: the second finder still reads back as
     * having no memory for the same person. This is the core §5.2f fix — finder A's miss must never make
     * the matcher believe finder B has already searched the person.
     *
     * @return void
     */
    #[Test]
    public function oneFindersMissDoesNotShadowAnother(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->record('I1', 'https://finder.a', new NegativeMemoryEntry(new SearchSignature('sig'), 1_000));

        self::assertInstanceOf(NegativeMemoryEntry::class, $store->find('I1', 'https://finder.a'));
        self::assertNull($store->find('I1', 'https://finder.b'));
    }

    /**
     * Two finders' misses for the same person coexist, each read back under its own finder id.
     *
     * @return void
     */
    #[Test]
    public function twoFindersMissesForOnePersonCoexist(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->record('I1', 'https://finder.a', new NegativeMemoryEntry(new SearchSignature('sig-a'), 1_000));
        $store->record('I1', 'https://finder.b', new NegativeMemoryEntry(new SearchSignature('sig-b'), 2_000));

        $a = $store->find('I1', 'https://finder.a');
        $b = $store->find('I1', 'https://finder.b');
        self::assertInstanceOf(NegativeMemoryEntry::class, $a);
        self::assertInstanceOf(NegativeMemoryEntry::class, $b);
        self::assertSame('sig-a', $a->signature->hash);
        self::assertSame('sig-b', $b->signature->hash);
    }

    /**
     * An unrecorded person reads back as null for any finder, not an error.
     *
     * @return void
     */
    #[Test]
    public function readsAnUnrecordedPersonAsNull(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        self::assertNull($store->find('I999', 'https://finder.a'));
    }

    /**
     * A second record for the same person AND finder overwrites the first (last-write-wins), matching a
     * re-drain, while leaving the other finder's memory untouched.
     *
     * @return void
     */
    #[Test]
    public function reRecordingTheSameFinderOverwritesOnlyThatFindersMiss(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->record('I1', 'https://finder.a', new NegativeMemoryEntry(new SearchSignature('old'), 1_000));
        $store->record('I1', 'https://finder.b', new NegativeMemoryEntry(new SearchSignature('keep'), 1_500));
        $store->record('I1', 'https://finder.a', new NegativeMemoryEntry(new SearchSignature('new'), 2_000));

        $a = $store->find('I1', 'https://finder.a');
        $b = $store->find('I1', 'https://finder.b');
        self::assertInstanceOf(NegativeMemoryEntry::class, $a);
        self::assertSame('new', $a->signature->hash);
        self::assertSame(2_000, $a->recordedAt);
        self::assertInstanceOf(NegativeMemoryEntry::class, $b);
        self::assertSame('keep', $b->signature->hash);
    }

    /**
     * Clearing a person drops EVERY finder's memory for that person (the §5.2f "any finder found the
     * person → the person is no longer a nothing-found case" reset).
     *
     * @return void
     */
    #[Test]
    public function clearingAPersonDropsEveryFindersMemory(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->record('I1', 'https://finder.a', new NegativeMemoryEntry(new SearchSignature('sig-a'), 1_000));
        $store->record('I1', 'https://finder.b', new NegativeMemoryEntry(new SearchSignature('sig-b'), 2_000));

        $store->clear('I1');

        self::assertNull($store->find('I1', 'https://finder.a'));
        self::assertNull($store->find('I1', 'https://finder.b'));
    }

    /**
     * Clearing an unrecorded person is a silent no-op, not an error (the drain clears on every Found
     * regardless of whether a prior miss existed).
     *
     * @return void
     */
    #[Test]
    public function clearingAnUnrecordedPersonIsANoOp(): void
    {
        $store = new FileNegativeMemoryStore($this->tmp . '/negative-memory');

        $store->clear('I404');

        self::assertNull($store->find('I404', 'https://finder.a'));
    }

    /**
     * A legacy single-finder document (written before §5.2f as `{personId, memory: {...}}`, with no
     * per-finder map) reads back as no memory rather than throwing: negative memory is a self-healing
     * soft cache, so the person is simply searched again and re-recorded in the per-finder shape.
     *
     * @return void
     */
    #[Test]
    public function readsALegacySingleFinderDocumentAsNoMemory(): void
    {
        $dir = $this->tmp . '/negative-memory';
        AtomicFile::ensureDirectory($dir);
        AtomicFile::writeJson(
            sprintf('%s/%s.json', $dir, hash('sha256', 'I1')),
            ['personId' => 'I1', 'memory' => ['signature' => 'legacy', 'recordedAt' => 1_000]]
        );

        self::assertNull((new FileNegativeMemoryStore($dir))->find('I1', 'https://finder.a'));
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
        $dir  = $this->tmp . '/negative-memory';
        $path = sprintf('%s/%s/%s.json', $dir, hash('sha256', 'I1'), hash('sha256', 'https://finder.a'));
        AtomicFile::ensureDirectory(dirname($path));
        file_put_contents($path, '{ not json');

        self::assertNull((new FileNegativeMemoryStore($dir))->find('I1', 'https://finder.a'));
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
        $dir  = $this->tmp . '/negative-memory';
        $path = sprintf('%s/%s/%s.json', $dir, hash('sha256', 'I1'), hash('sha256', 'https://finder.a'));
        AtomicFile::ensureDirectory(dirname($path));
        AtomicFile::writeJson(
            $path,
            ['personId' => 'I1', 'finderId' => 'https://finder.a', 'memory' => ['signature' => '', 'recordedAt' => 'nope']]
        );

        self::assertNull((new FileNegativeMemoryStore($dir))->find('I1', 'https://finder.a'));
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

        $store->record('I1', 'https://finder.a', $entry);
    }
}
