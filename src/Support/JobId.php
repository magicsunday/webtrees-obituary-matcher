<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use DateTimeImmutable;
use DateTimeZone;

use function bin2hex;
use function random_bytes;

/**
 * Mints a job identifier that sorts in creation order under a natural string sort: a fixed-width
 * UTC timestamp prefix plus a random 8-hex suffix that only breaks a same-second tie (the relative
 * order WITHIN one second is therefore unspecified, which the drain's per-job-independent ingest
 * does not depend on). The form `job-<YYYYMMDDTHHMMSSZ>-<8 hex>` is human-readable, timezone-free
 * and within {@see \MagicSunday\ObituaryMatcher\Queue\QueuePaths}'s path-traversal pattern.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class JobId
{
    /**
     * Mints a job id stamped with the given instant in UTC plus a random same-second tiebreak.
     *
     * @param DateTimeImmutable $now The instant to stamp (normalised to UTC).
     *
     * @return string The minted job id, e.g. "job-20260623T101530Z-a1b2c3d4".
     */
    public static function mint(DateTimeImmutable $now): string
    {
        $timestamp = $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

        return 'job-' . $timestamp . '-' . bin2hex(random_bytes(4));
    }
}
