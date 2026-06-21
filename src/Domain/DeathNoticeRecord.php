<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

use DateTimeImmutable;

/**
 * The richer death-notice shape a feeder produces, normalised for the matching engine.
 *
 * The raw display name is retained so later increments can re-extract roles without
 * re-fetching the source.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class DeathNoticeRecord
{
    /**
     * Constructor.
     *
     * @param NoticeType           $noticeType  Kind of notice this record was produced from.
     * @param string               $name        Raw display name from the notice.
     * @param PersonName           $parsedName  Parsed name parts (incl. "geb." birth surname).
     * @param DateRange            $birth       Birth date range (a bare year becomes a Year range).
     * @param DateRange            $death       Death date range; the result fact, usually exact.
     * @param Place|null           $place       Place mentioned in the notice, or null when absent.
     * @param Place|null           $cemetery    Cemetery named in the notice, or null when absent.
     * @param int|null             $age         Stated age at death, or null when absent.
     * @param DateRange            $funeralDate Funeral date range (DateRange::unknown() when absent).
     * @param list<NoticeRelative> $relatives   Relatives named in the notice, in source order.
     * @param string               $url         Source URL.
     * @param string               $source      Source/portal identifier.
     * @param DateTimeImmutable    $fetchedAt   Moment the notice was fetched.
     */
    public function __construct(
        public NoticeType $noticeType,
        public string $name,
        public PersonName $parsedName,
        public DateRange $birth,
        public DateRange $death,
        public ?Place $place,
        public ?Place $cemetery,
        public ?int $age,
        public DateRange $funeralDate,
        public array $relatives,
        public string $url,
        public string $source,
        public DateTimeImmutable $fetchedAt,
    ) {
    }
}
