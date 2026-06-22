<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use function preg_match;

/**
 * Formats an extracted obituary death date for display. The single source of the ISO `YYYY-MM-DD` to
 * German `DD.MM.YYYY` conversion shared by every view model, so the two presentation screens cannot
 * drift apart on the date shape they render.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryDateFormatter
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Formats an ISO `YYYY-MM-DD` death date as a German `DD.MM.YYYY` string, passing any other shape
     * (a non-ISO value or null) through unchanged.
     *
     * @param string|null $raw The raw extracted death date, or null when absent.
     *
     * @return string|null The formatted date, the unchanged raw value, or null.
     */
    public static function toGerman(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches) === 1) {
            return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
        }

        return $raw;
    }
}
