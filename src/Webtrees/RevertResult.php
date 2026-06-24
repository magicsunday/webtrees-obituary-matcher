<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

/**
 * The outcome of one {@see WriteBackReverter::revert()} run: the ids of the facts the revert actually
 * deleted (the DEAT, then the BURI when present). In normal mode this is every captured target; with
 * `--force` it is the subset that still resolved.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RevertResult
{
    /**
     * Constructor.
     *
     * @param list<string> $deletedFactIds The ids of the facts this revert deleted (DEAT, then BURI).
     */
    public function __construct(
        public array $deletedFactIds,
    ) {
    }
}
