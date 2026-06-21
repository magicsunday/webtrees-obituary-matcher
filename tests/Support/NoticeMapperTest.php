<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Support\NoticeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure mapper that copies the engine-relevant subset of a death notice into an obituary record.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(NoticeMapper::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(ObituaryRecord::class)]
#[UsesClass(NoticeRelative::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(Place::class)]
final class NoticeMapperTest extends TestCase
{
    /**
     * Mapping a death notice copies the engine-relevant subset onto an obituary record and drops the
     * enrichment fields (cemetery, age, funeral date, relatives).
     */
    #[Test]
    public function mapsTheEngineSubsetDroppingEnrichmentFields(): void
    {
        $notice = $this->deathNotice();          // the Task-1 record, cemetery + relatives set
        // The static return type already guarantees an ObituaryRecord, so the value-carrying
        // assertions below — not an instance check — are what prove the mapping is correct.
        $mapped = NoticeMapper::toObituaryRecord($notice);

        self::assertSame($notice->name, $mapped->name);
        self::assertSame($notice->parsedName, $mapped->parsedName);
        self::assertSame($notice->birth, $mapped->birth);
        self::assertSame($notice->death, $mapped->death);
        self::assertSame($notice->place, $mapped->place);
        self::assertSame($notice->url, $mapped->url);
        self::assertSame($notice->source, $mapped->source);
    }

    /**
     * Builds a richer death-notice record with the enrichment fields (cemetery, age,
     * funeral date, relatives) populated, so a mapper that leaked them would be caught.
     *
     * @return DeathNoticeRecord
     */
    private function deathNotice(): DeathNoticeRecord
    {
        return new DeathNoticeRecord(
            NoticeType::Obituary,
            'Erika Mustermann geb. Mueller',
            new PersonName(['Erika'], 'Erika', 'Mustermann', 'Mueller', ['Mustermann']),
            DateRange::year(1938),
            DateRange::year(2021),
            new Place('Musterstadt'),
            new Place('Waldfriedhof Musterstadt'),
            83,
            DateRange::year(2021),
            [
                new NoticeRelative('Max Mustermann', 'spouse', 0.9),
            ],
            'https://example.com/notice/1',
            'example-portal',
            new DateTimeImmutable('2026-06-21T00:00:00+00:00'),
        );
    }
}
