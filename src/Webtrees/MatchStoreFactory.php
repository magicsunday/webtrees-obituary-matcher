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
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;

use function rtrim;

/**
 * Builds the on-disk match-store directory for a tree. The store path is isolated by the numeric
 * tree id, so two trees that happen to share an XREF never write into the same store. The class is a
 * static-only utility: it holds no state and is never instantiated.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class MatchStoreFactory
{
    /**
     * The module's match-store base directory under webtrees' data directory.
     */
    private const string BASE_DIR = Webtrees::DATA_DIR . 'obituary-matcher/matches';

    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Builds the tree-scoped match store rooted at the module's base directory below webtrees' data
     * directory. Each tree receives its own isolated store keyed by the numeric tree id.
     *
     * @param Tree $tree The tree whose match store is requested.
     *
     * @return MatchStore The tree-scoped, file-backed match store.
     */
    public static function forTree(Tree $tree): MatchStore
    {
        return new FileMatchStore(self::pathForTree(self::BASE_DIR, $tree));
    }

    /**
     * Returns the tree-scoped store directory below the given base directory. The base directory's
     * trailing slash is normalised away and the numeric tree id is appended as a `tree-<id>` segment.
     *
     * @param string $baseDir The base directory that holds every tree's match store.
     * @param Tree   $tree    The tree whose store directory is requested.
     *
     * @return string The isolated, tree-scoped store directory.
     */
    public static function pathForTree(string $baseDir, Tree $tree): string
    {
        return rtrim($baseDir, '/') . '/tree-' . $tree->id();
    }
}
