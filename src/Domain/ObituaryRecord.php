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
 * One obituary notice, normalised. The raw display name is retained so later
 * increments can re-extract roles (e.g. "verw.") without re-scraping.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ObituaryRecord
{
    /**
     * Constructor.
     *
     * @param string     $name       Raw display name from the notice.
     * @param PersonName $parsedName Parsed name parts (incl. "geb." birth surname).
     * @param DateRange  $birth      Birth date range (a bare year becomes a Year range).
     * @param DateRange  $death      Death date range; the result fact, usually exact.
     * @param Place|null $place      Place mentioned in the notice, if any.
     * @param string     $url        Source URL.
     * @param string     $source     Source/portal identifier.
     */
    public function __construct(
        public string $name,
        public PersonName $parsedName,
        public DateRange $birth,
        public DateRange $death,
        public ?Place $place,
        public string $url,
        public string $source,
    ) {
    }
}
