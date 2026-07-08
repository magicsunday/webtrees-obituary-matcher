<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Domain\NegativeMemoryEntry;
use MagicSunday\ObituaryMatcher\Domain\SearchSignature;
use MagicSunday\ObituaryMatcher\Support\NegativeMemoryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the re-search policy (§5.2d): a search is suppressed only while a genuine miss is BOTH fresh
 * (within the TTL) AND still the same search (matching signature); otherwise the person is eligible
 * again.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(NegativeMemoryPolicy::class)]
#[UsesClass(NegativeMemoryEntry::class)]
#[UsesClass(SearchSignature::class)]
final class NegativeMemoryPolicyTest extends TestCase
{
    /**
     * With no recorded memory the search is never suppressed.
     *
     * @return void
     */
    #[Test]
    public function noMemoryNeverSuppresses(): void
    {
        $policy = new NegativeMemoryPolicy(100);

        self::assertFalse($policy->suppresses(null, new SearchSignature('sig'), 1_000));
    }

    /**
     * A fresh miss under the SAME signature suppresses the search.
     *
     * @return void
     */
    #[Test]
    public function freshSameSignatureMissSuppresses(): void
    {
        $policy = new NegativeMemoryPolicy(100);
        $memory = new NegativeMemoryEntry(new SearchSignature('sig'), 1_000);

        self::assertTrue($policy->suppresses($memory, new SearchSignature('sig'), 1_050));
    }

    /**
     * A miss whose signature differs from the current search never suppresses — the person's searched
     * data changed, so it is a genuinely new search.
     *
     * @return void
     */
    #[Test]
    public function differentSignatureNeverSuppresses(): void
    {
        $policy = new NegativeMemoryPolicy(100);
        $memory = new NegativeMemoryEntry(new SearchSignature('old'), 1_000);

        self::assertFalse($policy->suppresses($memory, new SearchSignature('new'), 1_050));
    }

    /**
     * A miss recorded longer ago than the TTL no longer suppresses — the person is searched again in
     * case new notices were published. The boundary is exclusive: exactly TTL seconds later is eligible.
     *
     * @return void
     */
    #[Test]
    public function expiredMissNoLongerSuppresses(): void
    {
        $policy = new NegativeMemoryPolicy(100);
        $memory = new NegativeMemoryEntry(new SearchSignature('sig'), 1_000);

        // now - recordedAt == 100 == TTL → NOT < TTL → eligible again.
        self::assertFalse($policy->suppresses($memory, new SearchSignature('sig'), 1_100));
        // One second before the TTL boundary still suppresses.
        self::assertTrue($policy->suppresses($memory, new SearchSignature('sig'), 1_099));
    }
}
