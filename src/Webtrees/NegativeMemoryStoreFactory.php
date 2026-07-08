<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\ObituaryMatcher\Matching\FileNegativeMemoryStore;
use MagicSunday\ObituaryMatcher\Matching\NegativeMemoryStore;

/**
 * Builds the on-disk per-person negative-memory store for a tree, in its own base directory beside the
 * match and coverage stores and isolated by the numeric tree id (reusing
 * {@see MatchStoreFactory::pathForTreeId()} as the single source of the tree-path layout). Static-only
 * utility: it holds no state and is never instantiated.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class NegativeMemoryStoreFactory
{
    /**
     * The module's negative-memory-store base directory under webtrees' data directory.
     */
    private const string BASE_DIR = Webtrees::DATA_DIR . 'obituary-matcher/negative-memory';

    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Builds the tree-scoped negative-memory store rooted at the module's base directory. Each tree
     * receives its own isolated store keyed by the numeric tree id.
     *
     * @param Tree $tree The tree whose negative-memory store is requested.
     *
     * @return NegativeMemoryStore The tree-scoped, file-backed negative-memory store.
     */
    public static function forTree(Tree $tree): NegativeMemoryStore
    {
        return new FileNegativeMemoryStore(MatchStoreFactory::pathForTreeId(self::BASE_DIR, $tree->id()));
    }
}
