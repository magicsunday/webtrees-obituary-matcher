<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;

use function sprintf;

/**
 * Harvests structured facts from an obituary record, currently the exact death date for later
 * write-back. Shared by {@see \MagicSunday\ObituaryMatcher\Scoring\MatchEngine} and the enriched
 * engine so the harvesting lives in exactly one place.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class DeathFactHarvester
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Harvests the exact death date when the notice carries one.
     *
     * @param ObituaryRecord $notice The obituary notice.
     *
     * @return array<string,string> Facts to harvest (deathDate when the death is exact).
     */
    public static function harvest(ObituaryRecord $notice): array
    {
        if (
            !$notice->death->isExact()
            || !$notice->death->earliest instanceof DateValue
        ) {
            return [];
        }

        $date = $notice->death->earliest;

        return [
            'deathDate' => sprintf('%04d-%02d-%02d', $date->year, $date->month ?? 1, $date->day ?? 1),
        ];
    }
}
