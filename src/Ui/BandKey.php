<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\Band;

/**
 * Normalises a raw classification band string to the allow-listed CSS-class key shared by every view
 * model. The allow-list is the {@see Band} enum's own label set, so the values that may pass through
 * to a CSS class have one single definition and a hostile band string (a CSS-class-injection vector)
 * collapses to "none".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class BandKey
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Normalises a raw classification to one of the {@see Band} enum's labels, collapsing any unknown
     * value to "none" so it can never inject an arbitrary CSS class.
     *
     * @param string $classification The raw classification band value.
     *
     * @return string The allow-listed band key.
     */
    public static function normalise(string $classification): string
    {
        foreach (Band::cases() as $band) {
            if ($band->value() === $classification) {
                return $classification;
            }
        }

        return Band::None->value();
    }
}
