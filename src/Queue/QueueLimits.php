<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

/**
 * Shared numeric limits for the queue layer. Holds the single source of truth for the feeder-file
 * size cap that both queue readers ({@see FeederRequestReader}, {@see ResponseReader}) and both
 * composition-root factories enforce, so the 5 MiB ceiling lives in exactly one place.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class QueueLimits
{
    /**
     * The maximum number of bytes a single feeder request/response file is read into memory,
     * guarding the queue readers against an oversized on-disk file (5 MiB).
     */
    public const int FEEDER_FILE_MAX_BYTES = 5_242_880;

    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }
}
