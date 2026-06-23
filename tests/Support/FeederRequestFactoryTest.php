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
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Support\CandidateQuery;
use MagicSunday\ObituaryMatcher\Support\FeederCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequest;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure factory that builds a serialisable feeder request from person candidates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FeederRequestFactory::class)]
#[CoversClass(FeederRequest::class)]
#[CoversClass(FeederCandidateRequest::class)]
#[UsesClass(QueryGenerator::class)]
#[UsesClass(CandidateQuery::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(DateRange::class)]
final class FeederRequestFactoryTest extends TestCase
{
    /**
     * The factory produces a request with the correct schema version, job metadata, and per-candidate query arrays.
     */
    #[Test]
    public function buildsRequestWithSchemaVersionAndPerCandidateQueries(): void
    {
        $c = new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Mueller', ['Mustermann']),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown()
        );
        $factory = new FeederRequestFactory(new QueryGenerator());
        $req     = $factory->build('job-1', new DateTimeImmutable('2026-06-20T00:00:00+00:00'), 'de-DE', [$c], 11);

        $array = $req->toArray();
        self::assertSame(3, $array['schemaVersion']);
        self::assertSame('job-1', $array['jobId']);
        self::assertSame('de-DE', $array['locale']);
        self::assertSame(11, $array['treeId']);
        self::assertSame('I1', $array['candidates'][0]['personId']);
        self::assertNotEmpty($array['candidates'][0]['queries']);

        // Each query is serialised with its plain text, numeric priority and dedup key. The
        // precise array shape already proves the keys exist, so assert their values carry the
        // generated query through unchanged.
        $firstQuery = $array['candidates'][0]['queries'][0];
        self::assertSame('Erika Mustermann 1938', $firstQuery['query']);
        self::assertSame(1, $firstQuery['priority']);
        self::assertSame('erika mustermann 1938', $firstQuery['dedupKey']);
    }

    /**
     * The factory threads a per-personId excludedHosts map onto the matching candidate and
     * serialises it; a candidate absent from the map serialises an empty list. Pins the
     * schema-3 bump.
     *
     * @return void
     */
    #[Test]
    public function buildThreadsExcludedHostsOntoTheMatchingCandidate(): void
    {
        $candidates = [
            new PersonCandidate(
                'I1',
                Gender::Female,
                new PersonName(['Erika'], null, 'Mustermann', 'Mueller', ['Mustermann']),
                DateRange::year(1938),
                null,
                [],
                DateRange::unknown()
            ),
            new PersonCandidate(
                'I2',
                Gender::Male,
                new PersonName(['Max'], null, 'Mustermann', 'Mueller', ['Mustermann']),
                DateRange::year(1940),
                null,
                [],
                DateRange::unknown()
            ),
        ];

        $request = (new FeederRequestFactory(new QueryGenerator()))->build(
            'job-20260623T101530Z-a1b2c3d4',
            new DateTimeImmutable('2026-06-23T10:15:30Z'),
            'de-DE',
            $candidates,
            7,
            ['I1' => ['example.test', 'other.test']],
        );

        $array = $request->toArray();

        self::assertSame(3, $array['schemaVersion']);
        self::assertSame(['example.test', 'other.test'], $array['candidates'][0]['excludedHosts']);
        self::assertSame([], $array['candidates'][1]['excludedHosts']);
    }
}
