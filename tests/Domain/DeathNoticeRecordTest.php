<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DeathNoticeRecord value object and its NoticeType/NoticeRelative parts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DeathNoticeRecord::class)]
#[CoversClass(NoticeRelative::class)]
#[CoversClass(NoticeType::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
final class DeathNoticeRecordTest extends TestCase
{
    /**
     * Verifies that fromStringOrDefault maps a known value and falls back to Obituary otherwise.
     */
    #[Test]
    public function fromStringOrDefaultMapsKnownAndUnknownTypes(): void
    {
        self::assertSame(NoticeType::GraveMemorial, NoticeType::fromStringOrDefault('grave_memorial'));
        self::assertSame(NoticeType::Obituary, NoticeType::fromStringOrDefault('not-a-type'));
    }

    /**
     * Verifies that DeathNoticeRecord exposes its constituent parts unchanged.
     */
    #[Test]
    public function deathNoticeRecordCarriesItsParts(): void
    {
        $record = new DeathNoticeRecord(
            NoticeType::Obituary,
            'Erika Mustermann geb. Mueller',
            new PersonName(['Erika'], null, 'Mustermann', 'Mueller'),
            DateRange::year(1938),
            DateRange::exact(new DateValue(2024, 3, 12)),
            new Place('Musterstadt'),
            new Place('Waldfriedhof Musterstadt'),
            86,
            DateRange::exact(new DateValue(2024, 3, 20)),
            [new NoticeRelative('Karl Mustermann', 'spouse', 0.9)],
            'https://example.test/traueranzeige/erika',
            'example.test',
            new DateTimeImmutable('2024-03-22T10:00:00+00:00'),
        );

        self::assertSame('Waldfriedhof Musterstadt', $record->cemetery?->name);
        self::assertSame(86, $record->age);
        self::assertSame('spouse', $record->relatives[0]->relationGuess);
        self::assertTrue($record->funeralDate->isExact());
        self::assertSame('example.test', $record->source);
    }
}
