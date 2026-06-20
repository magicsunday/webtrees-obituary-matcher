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
 * A qualitative confidence band for a match classification.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum Band
{
    case Strong;
    case Probable;
    case Possible;
    case Weak;
    case None;

    /**
     * Returns the lowercase string label for this band.
     *
     * @return string
     */
    public function value(): string
    {
        return match ($this) {
            self::Strong   => 'strong',
            self::Probable => 'probable',
            self::Possible => 'possible',
            self::Weak     => 'weak',
            self::None     => 'none',
        };
    }
}
