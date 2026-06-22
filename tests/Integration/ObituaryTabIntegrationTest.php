<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Ui\SuggestionTabPresenter;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use MagicSunday\ObituaryMatcher\Webtrees\MatchSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Drives the read-only presenter against the real on-disk {@see FileMatchStore}
 * (no fake store) so the two store-side guarantees the unit tests can only
 * assert against a stub are pinned end to end: a corrupt JSON row is skipped
 * rather than fatal, and two trees that happen to share an XREF never bleed
 * suggestions into each other because each tree gets its own store directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SuggestionTabPresenter::class)]
#[UsesClass(FileMatchStore::class)]
#[UsesClass(SuggestionViewModel::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
#[UsesClass(MatchSeeder::class)]
final class ObituaryTabIntegrationTest extends TestCase
{
    /**
     * The temporary store root created per test, removed recursively in {@see tearDown()} so a
     * failing assertion never leaks a directory tree under the system temp path.
     */
    private string $dir = '';

    /**
     * Removes the temp store directory tree regardless of how the test ended.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    /**
     * Recursively removes a directory and all of its contents, tolerating a path that was never
     * created.
     *
     * @param string $dir The directory to remove.
     *
     * @return void
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = glob($dir . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            if (is_dir($entry)) {
                $this->removeTree($entry);

                continue;
            }

            unlink($entry);
        }

        rmdir($dir);
    }

    /**
     * A directory holding one corrupt JSON row alongside a seeded valid row must
     * surface exactly the valid suggestion: the store tolerates the unreadable
     * row instead of letting it crash the read.
     *
     * @return void
     */
    #[Test]
    public function corruptRowIsSkippedValidRowSurfaces(): void
    {
        $dir       = sys_get_temp_dir() . '/om-int-' . bin2hex(random_bytes(4));
        $this->dir = $dir;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/bad.json', '{ not json');
        MatchSeeder::seed(new FileMatchStore($dir), 'I1', MatchStatus::Pending, 'strong', '2023-09-04');

        self::assertCount(1, (new SuggestionTabPresenter(new FileMatchStore($dir)))->suggestionsFor('I1'));
    }

    /**
     * Two trees seeded under separate store directories but sharing the same
     * XREF stay isolated: the second tree's presenter reports no content for the
     * XREF that only the first tree's store knows about.
     *
     * @return void
     */
    #[Test]
    public function twoTreesWithSameXrefStayIsolated(): void
    {
        $base      = sys_get_temp_dir() . '/om-trees-' . bin2hex(random_bytes(4));
        $this->dir = $base;
        MatchSeeder::seed(new FileMatchStore($base . '/tree-1'), 'I1', MatchStatus::Pending, 'strong', '2023-01-01');

        self::assertFalse((new SuggestionTabPresenter(new FileMatchStore($base . '/tree-2')))->hasContent('I1'));
    }
}
