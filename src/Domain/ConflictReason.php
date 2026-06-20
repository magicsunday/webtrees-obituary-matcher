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
 * A single field-level data conflict between a candidate and an obituary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ConflictReason
{
    /**
     * Constructor.
     *
     * @param string           $field         The name of the conflicting field.
     * @param string           $treeValue     The value found in the genealogy tree.
     * @param string           $obituaryValue The value found in the obituary.
     * @param ConflictSeverity $severity      The severity level of this conflict.
     */
    public function __construct(
        public string $field,
        public string $treeValue,
        public string $obituaryValue,
        public ConflictSeverity $severity,
    ) {
    }
}
