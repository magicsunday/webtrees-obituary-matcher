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
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Disposition;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\Place;

use function sprintf;
use function trim;

/**
 * Harvests structured facts for later write-back from two notice shapes, shared by
 * {@see \MagicSunday\ObituaryMatcher\Scoring\MatchEngine} and the enriched engine so the harvesting
 * lives in exactly one place: {@see harvest()} takes an {@see ObituaryRecord} and yields the exact
 * death date (the Phase-1 path), while {@see harvestFromNotice()} takes a {@see DeathNoticeRecord}
 * and yields the death date, the cemetery and the funeral date (the enriched path). Each fact is
 * harvested independently on its own condition.
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

    /**
     * Harvests the structured burial facts from the enriched notice, collecting each fact on its OWN
     * condition with no cross-dependency: a non-exact death date never suppresses the cemetery or the
     * funeral date (burial facts exist independently of the death date's precision).
     *
     * @param DeathNoticeRecord $notice The enriched death notice.
     *
     * @return array<string,string> Facts to harvest (deathDate, cemetery, funeralDate as present, plus
     *                              `disposition=cremation` when the notice is a cremation).
     */
    public static function harvestFromNotice(DeathNoticeRecord $notice): array
    {
        $facts = [];

        if (
            $notice->death->isExact()
            && $notice->death->earliest instanceof DateValue
        ) {
            $date = $notice->death->earliest;

            $facts['deathDate'] = sprintf('%04d-%02d-%02d', $date->year, $date->month ?? 1, $date->day ?? 1);
        }

        if (
            ($notice->cemetery instanceof Place)
            && (trim($notice->cemetery->name) !== '')
        ) {
            $facts['cemetery'] = trim($notice->cemetery->name);
        }

        if (
            $notice->funeralDate->isExact()
            && $notice->funeralDate->earliest instanceof DateValue
        ) {
            $date = $notice->funeralDate->earliest;

            $facts['funeralDate'] = sprintf('%04d-%02d-%02d', $date->year, $date->month ?? 1, $date->day ?? 1);
        }

        // Burial is the default, so only a cremation is stamped — the confirm write-back reads this to emit
        // a sourced CREM instead of a BURI. Absence therefore means burial.
        if ($notice->disposition === Disposition::Cremation) {
            $facts['disposition'] = Disposition::Cremation->value;
        }

        return $facts;
    }
}
