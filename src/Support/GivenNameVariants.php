<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function in_array;

/**
 * A curated seed of German given-name variant clusters, evaluated bidirectionally.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class GivenNameVariants
{
    /**
     * @var list<list<string>> Each inner list is one cluster of related names (normalised).
     */
    private const array CLUSTERS = [
        ['elisabeth', 'elise', 'else', 'lisa', 'liesel', 'lis', 'betty'],
        ['johann', 'johannes', 'hans', 'hannes'],
        ['margaretha', 'margarethe', 'margarete', 'grete', 'gretel', 'margret', 'meta'],
        ['katharina', 'katarina', 'kaethe', 'kathrin', 'kathi', 'trine'],
        ['heinrich', 'heinz', 'heiner'],
        ['wilhelm', 'willi', 'willy'],
        ['friedrich', 'fritz', 'friedel'],
        ['wolfgang', 'wolf'],
        ['gertrud', 'trude', 'traudel'],
        ['maria', 'marie', 'mia'],
    ];

    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Returns whether the two names are the same or belong to the same variant cluster.
     *
     * @param string $a First given name.
     * @param string $b Second given name.
     *
     * @return bool Whether the two names are the same or in the same variant cluster.
     */
    public static function areRelated(string $a, string $b): bool
    {
        $na = Normalizer::normalize($a);
        $nb = Normalizer::normalize($b);

        // An empty key is not a name: a stripped title ('' === '') must never relate to anything.
        if (
            ($na === '')
            || ($nb === '')
        ) {
            return false;
        }

        if ($na === $nb) {
            return true;
        }

        foreach (self::CLUSTERS as $cluster) {
            if (
                in_array($na, $cluster, true)
                && in_array($nb, $cluster, true)
            ) {
                return true;
            }
        }

        return false;
    }
}
