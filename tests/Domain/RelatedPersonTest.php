<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RelatedPerson value object.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(RelatedPerson::class)]
#[UsesClass(Gender::class)]
#[UsesClass(PersonName::class)]
final class RelatedPersonTest extends TestCase
{
    /**
     * Verifies that RelatedPerson exposes its constituent parts unchanged.
     */
    #[Test]
    public function relatedPersonHoldsItsParts(): void
    {
        $name    = new PersonName(['Karl'], null, 'Mustermann', null);
        $related = new RelatedPerson('I2', $name, Gender::Male);

        self::assertSame('I2', $related->id);
        self::assertSame($name, $related->name);
        self::assertSame('Karl', $related->name->givenNames[0]);
        self::assertSame(Gender::Male, $related->gender);
    }

    /**
     * Verifies that the gender defaults to Unknown when omitted.
     */
    #[Test]
    public function relatedPersonGenderDefaultsToUnknown(): void
    {
        $related = new RelatedPerson('I3', new PersonName(['Anna'], null, 'Mustermann', null));

        self::assertSame(Gender::Unknown, $related->gender);
    }
}
