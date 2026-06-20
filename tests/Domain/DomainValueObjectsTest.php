<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\DatePrecision;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateRangeStatus;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the domain value objects PersonName, Place, PersonCandidate and ObituaryRecord.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PersonName::class)]
#[CoversClass(Place::class)]
#[CoversClass(PersonCandidate::class)]
#[CoversClass(ObituaryRecord::class)]
#[UsesClass(DatePrecision::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateRangeStatus::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(Gender::class)]
#[UsesClass(RelatedPerson::class)]
final class DomainValueObjectsTest extends TestCase
{
    /**
     * Verifies that PersonCandidate exposes all constituent parts unchanged.
     */
    #[Test]
    public function candidateHoldsItsParts(): void
    {
        $candidate = new PersonCandidate(
            'I100',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', null),
            DateRange::year(1938),
            new Place('Musterstadt'),
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );
        self::assertSame('I100', $candidate->id);
        self::assertSame(Gender::Female, $candidate->gender);
        self::assertFalse($candidate->death->isKnown());
    }

    /**
     * Verifies that PersonCandidate carries the visible spouses and children from the family graph.
     */
    #[Test]
    public function candidateCarriesRelatives(): void
    {
        $spouse    = new RelatedPerson('I2', new PersonName(['Karl'], null, 'Mustermann', null), Gender::Male);
        $candidate = new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', null),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown(),
            [$spouse],
            [],
        );
        self::assertSame('I2', $candidate->spouses[0]->id);
        self::assertSame('Karl', $candidate->spouses[0]->name->givenNames[0]);
        self::assertSame([], $candidate->children);
    }

    /**
     * Verifies that ObituaryRecord carries known birth and exact death date ranges.
     */
    #[Test]
    public function obituaryAlwaysCarriesDateRanges(): void
    {
        $record = new ObituaryRecord(
            'Erika Mustermann',
            new PersonName(['Erika'], null, 'Mustermann', null),
            DateRange::year(1938),
            DateRange::exact(new DateValue(2023, 9, 4)),
            new Place('Musterstadt'),
            'https://example.test/x',
            'example.test',
        );
        self::assertTrue($record->birth->isKnown());
        self::assertTrue($record->death->isExact());
    }
}
