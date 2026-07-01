<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;

/**
 * Maps the engine-relevant subset of a {@see DeathNoticeRecord} onto the Phase-1 {@see ObituaryRecord}.
 *
 * Only the seven fields the scoring engine consumes are carried across. The enrichment fields
 * (noticeType, cemetery, age, funeralDate, relatives, fetchedAt) are deliberately DROPPED here:
 * Phase-2b scorers read those off the {@see DeathNoticeRecord} directly, so leaking them into the
 * leaner record would be redundant. This omission is intentional, not an oversight.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class NoticeMapper
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Copies the engine-relevant subset of a death notice into an obituary record.
     *
     * @param DeathNoticeRecord $notice Richer death-notice record produced by a finder.
     *
     * @return ObituaryRecord
     */
    public static function toObituaryRecord(DeathNoticeRecord $notice): ObituaryRecord
    {
        return new ObituaryRecord(
            $notice->name,
            $notice->parsedName,
            $notice->birth,
            $notice->death,
            $notice->place,
            $notice->url,
            $notice->source,
        );
    }
}
