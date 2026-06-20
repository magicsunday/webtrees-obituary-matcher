<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Support\CandidateQuery;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function array_values;
use function trim;

/**
 * Tests the pure query generator that builds prioritised, deduped, plain-text search queries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(QueryGenerator::class)]
#[CoversClass(CandidateQuery::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Place::class)]
#[UsesClass(DateRange::class)]
final class QueryGeneratorTest extends TestCase
{
    #[Test]
    public function generatesPrioritisedDedupedPlainTextQueries(): void
    {
        $c = new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], 'Erika', 'Mueller', 'Mueller', ['Mustermann']),
            DateRange::year(1938),
            new Place('Musterstadt'),
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );
        $queries = (new QueryGenerator())->generate($c);

        // most specific first: given + married + year
        self::assertSame('Erika Mustermann 1938', $queries[0]->query);
        self::assertSame(1, $queries[0]->priority);

        // plain text — no quotes/operators/keyword
        foreach ($queries as $q) {
            self::assertStringNotContainsString('"', $q->query);
            self::assertStringNotContainsString('Traueranzeige', $q->query);
        }

        // dedupKey is the normalised full query, not surname|year
        self::assertSame(Normalizer::strip('Erika Mustermann 1938'), $queries[0]->dedupKey);

        // no two queries share a dedupKey
        $keys = array_map(static fn ($q) => $q->dedupKey, $queries);
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    #[Test]
    public function skipsEmptyComponents(): void
    {
        $c = new PersonCandidate(
            'I9',
            Gender::Unknown,
            new PersonName(['Hans'], null, 'Beispiel', null),
            DateRange::unknown(),
            null,
            [],
            DateRange::unknown(),
        );

        foreach ((new QueryGenerator())->generate($c) as $q) {
            self::assertStringNotContainsString('  ', $q->query); // no double space from a missing year/place
            self::assertNotSame('', trim($q->query));
        }
    }
}
