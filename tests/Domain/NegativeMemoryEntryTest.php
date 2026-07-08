<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\NegativeMemoryEntry;
use MagicSunday\ObituaryMatcher\Domain\SearchSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the negative-memory entry's serialisation round-trip and its defensive read: a persisted row
 * rebuilds intact, and a corrupt row (missing/empty signature or non-int timestamp) is rejected as null
 * rather than throwing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(NegativeMemoryEntry::class)]
#[UsesClass(SearchSignature::class)]
final class NegativeMemoryEntryTest extends TestCase
{
    /**
     * A serialised entry rebuilds with its signature and timestamp intact.
     *
     * @return void
     */
    #[Test]
    public function roundTripsThroughAnArray(): void
    {
        $entry   = new NegativeMemoryEntry(new SearchSignature('abc123'), 1_700_000_000);
        $rebuilt = NegativeMemoryEntry::fromArray($entry->toArray());

        self::assertInstanceOf(NegativeMemoryEntry::class, $rebuilt);
        self::assertSame('abc123', $rebuilt->signature->hash);
        self::assertSame(1_700_000_000, $rebuilt->recordedAt);
    }

    /**
     * A corrupt row rebuilds as null (a defensive read of the store's own format).
     *
     * @param array<array-key, mixed> $row The corrupt stored row.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('corruptRowProvider')]
    public function fromArrayReturnsNullForACorruptRow(array $row): void
    {
        self::assertNull(NegativeMemoryEntry::fromArray($row));
    }

    /**
     * Corrupt rows the defensive read must reject.
     *
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function corruptRowProvider(): array
    {
        return [
            'no signature'         => [['recordedAt' => 1_700_000_000]],
            'empty signature'      => [['signature' => '', 'recordedAt' => 1_700_000_000]],
            'non-string signature' => [['signature' => 123, 'recordedAt' => 1_700_000_000]],
            'no timestamp'         => [['signature' => 'abc']],
            'non-int timestamp'    => [['signature' => 'abc', 'recordedAt' => 'yesterday']],
        ];
    }
}
