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
use MagicSunday\ObituaryMatcher\Matching\CoverageStore;
use MagicSunday\ObituaryMatcher\Matching\FileCoverageStore;

/**
 * Builds the on-disk per-person coverage store for a tree, in its own base directory beside the match
 * store and isolated by the numeric tree id (reusing {@see MatchStoreFactory::pathForTreeId()} as the
 * single source of the tree-path layout). Static-only utility: it holds no state and is never
 * instantiated.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class CoverageStoreFactory
{
    /**
     * The module's coverage-store base directory under webtrees' data directory.
     */
    private const string BASE_DIR = Webtrees::DATA_DIR . 'obituary-matcher/coverage';

    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Builds the tree-scoped coverage store rooted at the module's coverage base directory. Each tree
     * receives its own isolated store keyed by the numeric tree id.
     *
     * @param Tree $tree The tree whose coverage store is requested.
     *
     * @return CoverageStore The tree-scoped, file-backed coverage store.
     */
    public static function forTree(Tree $tree): CoverageStore
    {
        return new FileCoverageStore(MatchStoreFactory::pathForTreeId(self::BASE_DIR, $tree->id()));
    }
}
