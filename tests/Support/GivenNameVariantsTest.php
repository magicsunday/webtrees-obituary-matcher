<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the given-name variant lookup that relates short forms to their full names.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(GivenNameVariants::class)]
#[UsesClass(Normalizer::class)]
final class GivenNameVariantsTest extends TestCase
{
    /**
     * Verifies that variant names in the same cluster are recognised as related in both directions.
     */
    #[Test]
    public function relatedBothDirections(): void
    {
        self::assertTrue(GivenNameVariants::areRelated('Elisabeth', 'Lisa'));
        self::assertTrue(GivenNameVariants::areRelated('Lisa', 'Elisabeth'));
    }

    /**
     * Verifies that a name is always related to itself.
     */
    #[Test]
    public function identicalNamesAreRelated(): void
    {
        self::assertTrue(GivenNameVariants::areRelated('Anna', 'Anna'));
    }

    /**
     * Verifies that names belonging to different variant clusters are not considered related.
     */
    #[Test]
    public function unrelatedNamesAreNot(): void
    {
        self::assertFalse(GivenNameVariants::areRelated('Elisabeth', 'Margaretha'));
    }

    /**
     * Verifies that two names that both normalise to an empty string (academic titles stripped
     * to '') are never considered related: an empty key is not a name and must not match itself.
     */
    #[Test]
    public function emptyNormalisingNamesAreNotRelated(): void
    {
        self::assertFalse(GivenNameVariants::areRelated('Dr.', 'Prof.'));
    }
}
