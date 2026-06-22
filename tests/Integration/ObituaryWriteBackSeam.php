<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Webtrees\ObituaryWriteBack;

/**
 * A subclass that exposes {@see ObituaryWriteBack}'s protected source/host seams as public
 * methods so the integration test can drive them directly. It is a named class (rather than an
 * inline anonymous one) so static analysis fully types the `host`/`find`/`create` seams at
 * `level: max`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryWriteBackSeam extends ObituaryWriteBack
{
    /**
     * Exposes {@see ObituaryWriteBack::canonicalHost()}.
     *
     * @param string $url The source notice URL.
     *
     * @return string The canonical host.
     */
    public function host(string $url): string
    {
        return $this->canonicalHost($url);
    }

    /**
     * Exposes {@see ObituaryWriteBack::findPortalSource()}.
     *
     * @param Tree   $tree The tree to search.
     * @param string $host The canonical host.
     *
     * @return Source|null The matching source, or null when none exists.
     */
    public function find(Tree $tree, string $host): ?Source
    {
        return $this->findPortalSource($tree, $host);
    }

    /**
     * Exposes {@see ObituaryWriteBack::createPortalSource()}.
     *
     * @param Tree   $tree The tree to create the source in.
     * @param string $host The canonical host.
     *
     * @return Source The created source.
     */
    public function create(Tree $tree, string $host): Source
    {
        return $this->createPortalSource($tree, $host);
    }
}
