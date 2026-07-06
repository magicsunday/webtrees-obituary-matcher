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
 * The kind of death-related notice a finder produced.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum NoticeType: string
{
    /**
     * An obituary (Nachruf/Traueranzeige) describing the deceased.
     */
    case Obituary = 'obituary';

    /**
     * A plain death notice (Todesanzeige).
     */
    case DeathNotice = 'death_notice';

    /**
     * A funeral notice (Trauerfeier/Beisetzung announcement).
     */
    case FuneralNotice = 'funeral_notice';

    /**
     * A grave memorial entry (Grabmal/Gedenkstein).
     */
    case GraveMemorial = 'grave_memorial';

    /**
     * A cemetery record (Friedhofsregister entry).
     */
    case CemeteryRecord = 'cemetery_record';

    /**
     * Maps untrusted input to a case, defaulting to Obituary for an unknown value.
     *
     * @param string $raw Raw notice-type string from an external finder.
     */
    public static function fromStringOrDefault(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Obituary;
    }
}
