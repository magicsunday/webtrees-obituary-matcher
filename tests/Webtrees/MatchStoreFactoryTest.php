<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Webtrees\MatchStoreFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the tree-scoped match-store path logic: the store directory is isolated by
 * the numeric tree id, so two trees that share an XREF never collide on the same store, and a
 * trailing slash on the base directory is normalised away.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(MatchStoreFactory::class)]
final class MatchStoreFactoryTest extends TestCase
{
    #[Test]
    public function pathForTreeIsScopedByTreeId(): void
    {
        $tree = self::createStub(Tree::class);
        $tree->method('id')->willReturn(7);

        self::assertSame('/data/matches/tree-7', MatchStoreFactory::pathForTree('/data/matches', $tree));
    }

    #[Test]
    public function pathForTreeTrimsTrailingSlash(): void
    {
        $tree = self::createStub(Tree::class);
        $tree->method('id')->willReturn(3);

        self::assertSame('/data/matches/tree-3', MatchStoreFactory::pathForTree('/data/matches/', $tree));
    }

    #[Test]
    public function pathForTreeIdIsScopedByTreeId(): void
    {
        self::assertSame('/data/matches/tree-7', MatchStoreFactory::pathForTreeId('/data/matches', 7));
    }

    #[Test]
    public function pathForTreeIdTrimsTrailingSlash(): void
    {
        self::assertSame('/data/matches/tree-3', MatchStoreFactory::pathForTreeId('/data/matches/', 3));
    }
}
