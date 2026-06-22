<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the fixture seeder: a seeded row round-trips through a real file-backed store,
 * reads back as exactly one non-terminal suggestion and carries the synthetic classification, score
 * and extracted facts the seeder was asked to write. The band→score map, the unknown-band fallback
 * and the no-facts branch are each exercised so the seeder's only logic is covered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(MatchSeeder::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
final class MatchSeederTest extends TestCase
{
    use RemovesFlatTempStoreTrait;

    /**
     * The temporary store directory created per test, removed in {@see tearDown()} so a failing
     * assertion never leaks a directory under the system temp path.
     */
    private string $dir = '';

    /**
     * Creates a fresh, unique temp store directory path for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->makeFlatStoreDir('om-seed-');
    }

    /**
     * Removes the temp store directory and its rows regardless of how the test ended.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeFlatStoreDir();

        parent::tearDown();
    }

    /**
     * Verifies that a seeded strong suggestion round-trips through a real file-backed store: it reads
     * back as exactly one non-terminal row carrying the synthetic classification, band score,
     * fabricated source URL and the requested death date, matching the StoredMatch the seeder returns.
     *
     * @return void
     */
    #[Test]
    public function seededRowIsReadableAndNonTerminal(): void
    {
        $store = new FileMatchStore($this->dir);

        $written = MatchSeeder::seed($store, 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        $rows = $store->findByPerson('I1');

        self::assertCount(1, $rows, 'seed() writes exactly one pending suggestion per call');
        self::assertFalse($rows[0]->status->isTerminal());
        self::assertSame('strong', $rows[0]->match['classification']);
        self::assertSame(92, $rows[0]->match['score']);
        self::assertSame('https://trauer.example/I1', $rows[0]->match['obituaryUrl']);
        self::assertSame('2023-09-04', $rows[0]->match['extractedFacts']['deathDate']);

        // The returned StoredMatch is the row that was persisted.
        self::assertSame('I1', $written->personId);
        self::assertSame('https://trauer.example/I1', $written->obituaryUrl);
        self::assertSame($rows[0]->match['score'], $written->match['score']);
    }

    /**
     * Verifies that an unmapped band falls back to the default score and that a null death date yields
     * an empty extracted-facts set.
     *
     * @return void
     */
    #[Test]
    public function unknownBandFallsBackToDefaultScoreAndNullDeathYieldsNoFacts(): void
    {
        $store = new FileMatchStore($this->dir);

        MatchSeeder::seed($store, 'I2', MatchStatus::Pending, 'unmapped', null);

        $rows = $store->findByPerson('I2');

        self::assertCount(1, $rows, 'seed() writes exactly one pending suggestion per call');
        self::assertSame('unmapped', $rows[0]->match['classification']);
        self::assertSame(50, $rows[0]->match['score']);
        self::assertSame([], $rows[0]->match['extractedFacts']);
    }
}
