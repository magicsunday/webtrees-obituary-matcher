<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Contract;

use DateTimeImmutable;
use JsonException;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Support\CandidateQuery;
use MagicSunday\ObituaryMatcher\Support\FinderCandidateRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequest;
use MagicSunday\ObituaryMatcher\Support\FinderRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function json_encode;
use function range;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * The producer-side contract gate: the REAL `FinderRequest::toArray()` output — as assembled by
 * {@see FinderRequestFactory} from a representative {@see PersonCandidate} — validates against the
 * published `schemas/job-request.schema.json`. This pins the wire body a `POST /jobs` submission
 * carries onto the #56 contract, so a drift in the projection (a stray internal key, a missing
 * required `names`, a wrong `schemaVersion`) reds here rather than at a finder.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class JobRequestProducerContractTest extends TestCase
{
    /**
     * The published schemas directory (repo root).
     */
    private const string SCHEMA_DIR = __DIR__ . '/../../schemas';

    /**
     * The `$id` the job-request schema is registered under.
     */
    private const string SCHEMA_ID = 'https://raw.githubusercontent.com/magicsunday/webtrees-obituary-matcher/main/schemas/job-request.schema.json';

    /**
     * The real producer output validates against the published job-request schema, and none of the
     * retired internal keys (`treeId`, `createdAt`, per-candidate `excludedHosts`) leak onto the wire.
     *
     * @return void
     *
     * @throws JsonException If the produced body is not encodable/decodable JSON.
     */
    #[Test]
    public function theRealProducerOutputValidatesAgainstThePublishedSchema(): void
    {
        $candidate = new PersonCandidate(
            'I1',
            Gender::Female,
            new PersonName(['Erika', 'Maria'], null, 'Mustermann', 'Mueller', ['Schmidt']),
            DateRange::year(1938),
            null,
            [],
            DateRange::unknown()
        );

        $request = (new FinderRequestFactory(new QueryGenerator()))->build(
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [$candidate],
            11,
        );

        $json = json_encode($request->toArray(), JSON_THROW_ON_ERROR);

        // Validate the REAL body against the published schema (object-faithful decode for opis).
        $result = $this->validator()->validate(
            json_decode($json, flags: JSON_THROW_ON_ERROR),
            self::SCHEMA_ID
        );

        self::assertTrue($result->isValid(), 'The producer output does not validate against job-request.schema.json');

        // Re-decode as an associative array (type erased) so the explicit key assertions below are
        // runtime checks the schema's additionalProperties/required already enforce, not tautologies
        // PHPStan can narrow away from the toArray() shape.
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        // The contract MAJOR is stamped, and the retired internal envelope keys are gone from the wire.
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertArrayNotHasKey('treeId', $decoded);
        self::assertArrayNotHasKey('createdAt', $decoded);

        $candidates = $decoded['candidates'];
        self::assertIsArray($candidates);
        $candidateBody = $candidates[0];
        self::assertIsArray($candidateBody);
        self::assertArrayNotHasKey('excludedHosts', $candidateBody);
        self::assertArrayNotHasKey('queries', $candidateBody);
        self::assertArrayHasKey('names', $candidateBody);
        self::assertNotEmpty($candidateBody['names']);
    }

    /**
     * Every representative candidate shape — including the domain edge cases that could otherwise emit
     * a schema-invalid body (a single-role name, an over-long field, an over-cap list, an empty
     * dedupKey) — projects onto a body that validates against the published job-request schema, and the
     * decoded `names[]`/`queryHints[]` carry exactly the projected, capped, truncated entries.
     *
     * @param FinderCandidateRequest $candidate The candidate whose projection is validated.
     * @param array{
     *   names?: list<array<string, string>>,
     *   namesCount?: int,
     *   firstNameKind?: string,
     *   queryHints?: list<array{query: string, dedupKey: string, priority: int}>,
     *   queryHintsCount?: int
     * } $expected The targeted assertions on the decoded candidate body.
     *
     * @return void
     *
     * @throws JsonException If the produced body is not encodable/decodable JSON.
     */
    #[Test]
    #[DataProvider('candidateShapeProvider')]
    public function everyCandidateShapeProjectsOntoAValidContractBody(FinderCandidateRequest $candidate, array $expected): void
    {
        $request = new FinderRequest(
            'job-1',
            new DateTimeImmutable('2026-06-20T00:00:00+00:00'),
            'de-DE',
            [$candidate],
            11,
        );

        $json = json_encode($request->toArray(), JSON_THROW_ON_ERROR);

        $result = $this->validator()->validate(
            json_decode($json, flags: JSON_THROW_ON_ERROR),
            self::SCHEMA_ID
        );

        self::assertTrue($result->isValid(), 'The candidate projection does not validate against job-request.schema.json');

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $candidates = $decoded['candidates'];
        self::assertIsArray($candidates);
        $candidateBody = $candidates[0];
        self::assertIsArray($candidateBody);

        $names = $candidateBody['names'];
        self::assertIsArray($names);

        if (isset($expected['names'])) {
            self::assertSame($expected['names'], $names);
        }

        if (isset($expected['namesCount'])) {
            self::assertCount($expected['namesCount'], $names);
        }

        if (isset($expected['firstNameKind'])) {
            $firstName = $names[0];
            self::assertIsArray($firstName);
            self::assertSame($expected['firstNameKind'], $firstName['kind']);
        }

        $queryHints = $candidateBody['queryHints'];
        self::assertIsArray($queryHints);

        if (isset($expected['queryHints'])) {
            self::assertSame($expected['queryHints'], $queryHints);
        }

        if (isset($expected['queryHintsCount'])) {
            self::assertCount($expected['queryHintsCount'], $queryHints);
        }
    }

    /**
     * The candidate shapes exercised against the published schema. Each single-role name proves the
     * projection still emits a non-empty `names` (the schema's `minItems: 1`); the cap/truncate rows
     * prove the contract maxima are enforced; the empty-dedupKey row proves the invalid hint is dropped.
     *
     * @return array<string, array{FinderCandidateRequest, array<string, mixed>}> The provider rows.
     */
    public static function candidateShapeProvider(): array
    {
        $longField = str_repeat('a', 250);

        /** @var list<CandidateQuery> $manyQueries */
        $manyQueries = array_map(
            static fn (int $n): CandidateQuery => new CandidateQuery('Suche ' . $n, 1, 'suche' . $n),
            range(0, 59),
        );

        return [
            'given only' => [
                new FinderCandidateRequest('I1', new PersonName(['Erika'], null, '', null), []),
                ['names' => [['kind' => 'primary', 'given' => 'Erika']]],
            ],
            'surname only' => [
                new FinderCandidateRequest('I1', new PersonName([], null, 'Mustermann', null), []),
                ['names' => [['kind' => 'primary', 'surname' => 'Mustermann']]],
            ],
            'birth surname only' => [
                new FinderCandidateRequest('I1', new PersonName([], null, '', 'Mueller'), []),
                ['names' => [['kind' => 'birth', 'surname' => 'Mueller']]],
            ],
            'married only' => [
                new FinderCandidateRequest('I1', new PersonName([], null, '', null, ['Schmidt']), []),
                ['names' => [['kind' => 'married', 'surname' => 'Schmidt']]],
            ],
            'alias only' => [
                new FinderCandidateRequest('I1', new PersonName([], null, '', null, [], ['Erika Musterfrau']), []),
                ['names' => [['kind' => 'alias', 'full' => 'Erika Musterfrau']]],
            ],
            'more than ten name forms are capped at ten with the primary first' => [
                new FinderCandidateRequest(
                    'I1',
                    new PersonName(
                        ['Erika'],
                        null,
                        'Mustermann',
                        'Mueller',
                        ['M1', 'M2', 'M3', 'M4'],
                        ['A1', 'A2', 'A3', 'A4', 'A5', 'A6'],
                    ),
                    [],
                ),
                ['namesCount' => 10, 'firstNameKind' => 'primary'],
            ],
            'an over-long name field is truncated to the contract maximum' => [
                new FinderCandidateRequest('I1', new PersonName([], null, $longField, null), []),
                ['names' => [['kind' => 'primary', 'surname' => str_repeat('a', 200)]]],
            ],
            'more than fifty queries are capped at fifty' => [
                new FinderCandidateRequest('I1', new PersonName([], null, 'Mustermann', null), $manyQueries),
                ['queryHintsCount' => 50],
            ],
            'a query whose dedupKey strips to empty is skipped' => [
                new FinderCandidateRequest(
                    'I1',
                    new PersonName([], null, 'Mustermann', null),
                    [
                        new CandidateQuery('die', 1, ''),
                        new CandidateQuery('Mustermann', 2, 'mustermann'),
                    ],
                ),
                ['queryHints' => [['query' => 'Mustermann', 'dedupKey' => 'mustermann', 'priority' => 2]]],
            ],
        ];
    }

    /**
     * Builds a validator with the job-request schema registered by its `$id` so it resolves offline.
     *
     * @return Validator The configured validator.
     */
    private function validator(): Validator
    {
        $validator = new Validator();

        $resolver = $validator->resolver();
        self::assertNotNull($resolver, 'opis validator must expose a schema resolver');

        $resolver->registerFile(self::SCHEMA_ID, self::SCHEMA_DIR . '/job-request.schema.json');

        return $validator;
    }
}
