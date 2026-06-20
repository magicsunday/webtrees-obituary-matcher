<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * A tree person normalised for matching.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PersonCandidate
{
    /**
     * Constructor.
     *
     * @param string      $id         Stable identifier (e.g. the GEDCOM xref).
     * @param Gender      $gender     Recorded sex, or Unknown.
     * @param PersonName  $name       Decomposed name.
     * @param DateRange   $birth      Birth date range.
     * @param Place|null  $birthPlace Birth place, if known.
     * @param list<Place> $places     Known residences / associated places.
     * @param DateRange   $death      Death date range; Unknown for forward-search candidates.
     */
    public function __construct(
        public string $id,
        public Gender $gender,
        public PersonName $name,
        public DateRange $birth,
        public ?Place $birthPlace,
        public array $places,
        public DateRange $death,
    ) {
    }
}
