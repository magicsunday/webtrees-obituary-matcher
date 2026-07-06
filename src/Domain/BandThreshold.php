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
 * The fixed, inclusive lower score bounds for the confidence bands. They are the single Domain source
 * shared by the {@see \MagicSunday\ObituaryMatcher\Scoring\Classifier} (which bands a score by them) and
 * the control panel (which shows them read-only). Living in the Domain layer keeps the Webtrees adapter
 * from reaching into the Scoring layer just to present them. They are NOT admin-editable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class BandThreshold
{
    /**
     * Lowest total for the Strong band.
     */
    public const int STRONG = 85;

    /**
     * Lowest total for the Probable band.
     */
    public const int PROBABLE = 70;

    /**
     * Lowest total for the Possible band.
     */
    public const int POSSIBLE = 55;

    /**
     * Lowest total for the Weak band.
     */
    public const int WEAK = 40;

    /**
     * Static-only constant holder: no instances.
     */
    private function __construct()
    {
    }

    /**
     * The four band thresholds as a keyed map, for the read-only control-panel presentation.
     *
     * @return array{strong: int, probable: int, possible: int, weak: int} The band thresholds.
     */
    public static function all(): array
    {
        return [
            'strong'   => self::STRONG,
            'probable' => self::PROBABLE,
            'possible' => self::POSSIBLE,
            'weak'     => self::WEAK,
        ];
    }
}
