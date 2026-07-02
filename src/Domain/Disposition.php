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
 * How the deceased's body was disposed of, as indicated by the notice — a burial or a cremation. It
 * selects which sourced GEDCOM event the confirm write-back emits (`BURI` vs `CREM`). Burial is the
 * default when a notice carries no explicit disposition.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum Disposition: string
{
    /**
     * An interment — writes a sourced `BURI` event.
     */
    case Burial = 'burial';

    /**
     * A cremation — writes a sourced `CREM` event.
     */
    case Cremation = 'cremation';
}
