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
use MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure factory that builds a serialisable finder request from person candidates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderRequestFactory::class)]
#[CoversClass(FinderRequest::class)]
#[CoversClass(FinderCandidateRequest::class)]
#[UsesClass(QueryGenerator::class)]
#[UsesClass(CandidateQuery::class)]
#[UsesClass(PersonCandidate::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(DateRange::class)]
final class FinderRequestFactoryTest extends TestCase
{
    /**
     * The factory produces a contract-shaped request: the contract MAJOR (1), the job metadata, and a
     * per-candidate body carrying the projected `names` and the pre-built `queryHints`.
     */
    #[Test]
    public function buildsContractShapedRequestWithNamesAndQueryHints(): void
    {
        $c = new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika'], null, 'Mustermann', 'Mueller', ['Schmidt']),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown()
        );
        $factory = new FinderRequestFactory(new QueryGenerator());
        $req     = $factory->build('job-1', new DateTimeImmutable('2026-06-20T00:00:00+00:00'), 'de-DE', [$c], 11);

        $array = $req->toArray();
        self::assertSame(1, $array['schemaVersion']);
        self::assertSame('job-1', $array['jobId']);
        self::assertSame('de-DE', $array['locale']);
        self::assertArrayNotHasKey('treeId', $array);
        self::assertArrayNotHasKey('createdAt', $array);

        $candidate = $array['candidates'][0];
        self::assertSame('I1', $candidate['personId']);
        self::assertArrayNotHasKey('excludedHosts', $candidate);
        self::assertArrayNotHasKey('queries', $candidate);

        // The decomposed name projects onto one entry per known form: primary (given + surname),
        // birth (the distinct Geburtsname) and married.
        self::assertSame(
            [
                ['kind' => 'primary', 'given' => 'Erika', 'surname' => 'Mustermann'],
                ['kind' => 'birth', 'surname' => 'Mueller'],
                ['kind' => 'married', 'surname' => 'Schmidt'],
            ],
            $candidate['names']
        );

        // Each query is serialised as a contract query hint with its plain text, dedup key and
        // numeric priority carried through unchanged.
        // The priority-1 query is given + married surname + birth year (see QueryGenerator).
        $firstHint = $candidate['queryHints'][0];
        self::assertSame('Erika Schmidt 1938', $firstHint['query']);
        self::assertSame('erika schmidt 1938', $firstHint['dedupKey']);
        self::assertSame(1, $firstHint['priority']);
    }

    /**
     * The factory threads a per-personId excludedHosts map onto the matching candidate object, but the
     * hint stays OFF the wire (not part of the published contract); a candidate absent from the map
     * carries an empty list.
     *
     * @return void
     */
    #[Test]
    public function buildThreadsExcludedHostsOntoTheCandidateObjectButNotTheWire(): void
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

        $request = (new FinderRequestFactory(new QueryGenerator()))->build(
            'job-20260623T101530Z-a1b2c3d4',
            new DateTimeImmutable('2026-06-23T10:15:30Z'),
            'de-DE',
            $candidates,
            7,
            ['I1' => ['example.test', 'other.test']],
        );

        // The hint is threaded onto the candidate object.
        self::assertSame(['example.test', 'other.test'], $request->candidates[0]->excludedHosts);
        self::assertSame([], $request->candidates[1]->excludedHosts);

        // ... but never serialised onto the wire.
        $array = $request->toArray();
        self::assertSame(1, $array['schemaVersion']);
        self::assertArrayNotHasKey('excludedHosts', $array['candidates'][0]);
        self::assertArrayNotHasKey('excludedHosts', $array['candidates'][1]);
    }
}
