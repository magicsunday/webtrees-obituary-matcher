<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use DateTimeImmutable;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\DateValue;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\NoticeRelative;
use MagicSunday\ObituaryMatcher\Domain\NoticeType;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;

/**
 * The single curated "Erika Mustermann" worked example shared by the enriched-engine tests, so the
 * candidate/notice fixtures live in exactly one place (no jscpd clone between the two suites).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
trait EnrichedWorkedExampleFixtureTrait
{
    /**
     * The curated tree candidate: Erika Mustermann geb. Beispiel, born 1951-06-22, residing in
     * Musterstadt, with the spouse Karl Mustermann and no recorded death.
     *
     * @return PersonCandidate
     */
    private function workedExampleCandidate(): PersonCandidate
    {
        return new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::exact(new DateValue(1951, 6, 22)),
            null,
            [new Place('Musterstadt')],
            DateRange::unknown(),
            [new RelatedPerson('S1', new PersonName(['Karl'], null, 'Mustermann', null))],
            [],
        );
    }

    /**
     * The matching enriched death notice for the curated candidate, carrying the spouse, the stated
     * age 73, and the Waldfriedhof Musterstadt cemetery.
     *
     * @return DeathNoticeRecord
     */
    private function workedExampleNotice(): DeathNoticeRecord
    {
        return new DeathNoticeRecord(
            NoticeType::Obituary,
            'Erika Mustermann geb. Beispiel',
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::exact(new DateValue(1951, 6, 22)),
            DateRange::exact(new DateValue(2024, 3, 1)),
            new Place('Musterstadt'),
            new Place('Waldfriedhof Musterstadt'),
            73,
            DateRange::unknown(),
            [new NoticeRelative('Karl Mustermann', 'spouse', 1.0)],
            'https://obituary.example/notice/erika',
            'portal',
            new DateTimeImmutable('2026-06-21T10:00:00+00:00'),
        );
    }

    /**
     * A leaner curated candidate for the non-clamping example: the same person, but WITHOUT a
     * recorded residence place, so the place signal cannot fire and the total stays below 100.
     *
     * @return PersonCandidate
     */
    private function probableExampleCandidate(): PersonCandidate
    {
        return new PersonCandidate(
            'I2',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::exact(new DateValue(1951, 6, 22)),
            null,
            [],
            DateRange::unknown(),
            [],
            [],
        );
    }

    /**
     * The matching notice for the non-clamping example: exact name + exact birth only. No place,
     * no cemetery, no stated age, no relatives — so only name, birth and plausibility score and the
     * un-clamped total (35 + 25 + 10 = 70) stays strictly below the 100 clamp.
     *
     * @return DeathNoticeRecord
     */
    private function probableExampleNotice(): DeathNoticeRecord
    {
        return new DeathNoticeRecord(
            NoticeType::Obituary,
            'Erika Mustermann geb. Beispiel',
            new PersonName(['Erika'], null, 'Mustermann', 'Beispiel'),
            DateRange::exact(new DateValue(1951, 6, 22)),
            DateRange::exact(new DateValue(2024, 3, 1)),
            null,
            null,
            null,
            DateRange::unknown(),
            [],
            'https://obituary.example/notice/erika-probable',
            'portal',
            new DateTimeImmutable('2026-06-21T10:00:00+00:00'),
        );
    }
}
