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
 * A spouse or child surfaced from the family graph around a candidate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RelatedPerson
{
    /**
     * Constructor.
     *
     * @param string     $id     Stable identifier of the related individual.
     * @param PersonName $name   The relative's decomposed name.
     * @param Gender     $gender The relative's gender, or Unknown.
     */
    public function __construct(
        public string $id,
        public PersonName $name,
        public Gender $gender = Gender::Unknown,
    ) {
    }
}
