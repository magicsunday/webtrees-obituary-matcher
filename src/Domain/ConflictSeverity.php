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
 * The severity level of a detected data conflict between a candidate and an obituary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum ConflictSeverity
{
    case Hard;
    case Soft;

    /**
     * Returns the lowercase string label for this severity level.
     *
     * @return string
     */
    public function value(): string
    {
        return match ($this) {
            self::Hard => 'hard',
            self::Soft => 'soft',
        };
    }
}
