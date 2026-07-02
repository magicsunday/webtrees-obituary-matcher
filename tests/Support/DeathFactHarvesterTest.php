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
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Disposition;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Support\DeathFactHarvester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the notice-aware harvest: each fact (deathDate, cemetery, funeralDate) is collected on its
 * own condition, with no cross-dependency between them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(DeathFactHarvester::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(DateValue::class)]
#[UsesClass(DeathNoticeRecord::class)]
#[UsesClass(NoticeType::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
final class DeathFactHarvesterTest extends TestCase
{
    /**
     * Each fact is harvested independently of the others.
     *
     * @param DeathNoticeRecord    $notice   The enriched death notice.
     * @param array<string,string> $expected The facts the notice must yield.
     */
    #[Test]
    #[DataProvider('noticeFactProvider')]
    public function harvestFromNoticeCollectsEachFactIndependently(
        DeathNoticeRecord $notice,
        array $expected,
    ): void {
        self::assertSame($expected, DeathFactHarvester::harvestFromNotice($notice));
    }

    /**
     * One row per branch combination; each row is commented with the dimension it spans.
     *
     * @return array<string, array{0: DeathNoticeRecord, 1: array<string,string>}>
     */
    public static function noticeFactProvider(): array
    {
        return [
            // cemetery-set + funeral-exact + death-exact -> all three facts.
            'all three facts' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('Waldfriedhof'),
                    funeralDate: DateRange::exact(new DateValue(2024, 3, 8)),
                ),
                [
                    'deathDate'   => '2024-03-01',
                    'cemetery'    => 'Waldfriedhof',
                    'funeralDate' => '2024-03-08',
                ],
            ],

            // cremation disposition -> disposition=cremation stamped alongside the other facts.
            'cremation stamps disposition' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('Waldfriedhof'),
                    funeralDate: DateRange::exact(new DateValue(2024, 3, 8)),
                    disposition: Disposition::Cremation,
                ),
                [
                    'deathDate'   => '2024-03-01',
                    'cemetery'    => 'Waldfriedhof',
                    'funeralDate' => '2024-03-08',
                    'disposition' => 'cremation',
                ],
            ],

            // burial disposition -> NO disposition key (burial is the default, absence means burial).
            'burial stamps no disposition' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('Waldfriedhof'),
                    funeralDate: DateRange::unknown(),
                    disposition: Disposition::Burial,
                ),
                [
                    'deathDate' => '2024-03-01',
                    'cemetery'  => 'Waldfriedhof',
                ],
            ],

            // cemetery-set + funeral-NOT-exact + death-exact -> funeralDate suppressed only.
            'funeral not exact drops funeral' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('Waldfriedhof'),
                    funeralDate: DateRange::unknown(),
                ),
                [
                    'deathDate' => '2024-03-01',
                    'cemetery'  => 'Waldfriedhof',
                ],
            ],

            // cemetery-null + funeral-exact + death-exact -> cemetery suppressed only.
            'no cemetery drops cemetery' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: null,
                    funeralDate: DateRange::exact(new DateValue(2024, 3, 8)),
                ),
                [
                    'deathDate'   => '2024-03-01',
                    'funeralDate' => '2024-03-08',
                ],
            ],

            // cemetery whitespace-only -> cemetery absent.
            'whitespace cemetery is absent' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('   '),
                    funeralDate: DateRange::unknown(),
                ),
                [
                    'deathDate' => '2024-03-01',
                ],
            ],

            // cemetery padded with whitespace -> trimmed value.
            'padded cemetery is trimmed' => [
                self::notice(
                    death: DateRange::exact(new DateValue(2024, 3, 1)),
                    cemetery: new Place('  X  '),
                    funeralDate: DateRange::unknown(),
                ),
                [
                    'deathDate' => '2024-03-01',
                    'cemetery'  => 'X',
                ],
            ],

            // death-NOT-exact + cemetery-set -> burial fact survives a fuzzy death date (independence).
            'fuzzy death keeps cemetery' => [
                self::notice(
                    death: DateRange::year(2024),
                    cemetery: new Place('Waldfriedhof'),
                    funeralDate: DateRange::unknown(),
                ),
                [
                    'cemetery' => 'Waldfriedhof',
                ],
            ],

            // death-NOT-exact + nothing else -> empty.
            'fuzzy death nothing else' => [
                self::notice(
                    death: DateRange::year(2024),
                    cemetery: null,
                    funeralDate: DateRange::unknown(),
                ),
                [],
            ],
        ];
    }

    /**
     * Builds a death notice varying only the three harvested dimensions; the rest is fixed scaffolding.
     *
     * @param DateRange  $death       The death date range.
     * @param Place|null $cemetery    The cemetery, or null when absent.
     * @param DateRange  $funeralDate The funeral date range.
     *
     * @return DeathNoticeRecord
     */
    private static function notice(
        DateRange $death,
        ?Place $cemetery,
        DateRange $funeralDate,
        Disposition $disposition = Disposition::Burial,
    ): DeathNoticeRecord {
        return new DeathNoticeRecord(
            NoticeType::Obituary,
            'Erika Mustermann',
            new PersonName(['Erika'], null, 'Mustermann', null),
            DateRange::unknown(),
            $death,
            null,
            $cemetery,
            null,
            $funeralDate,
            [],
            'https://obituary.example/notice/erika',
            'portal',
            new DateTimeImmutable('2026-06-21T10:00:00+00:00'),
            $disposition,
        );
    }
}
