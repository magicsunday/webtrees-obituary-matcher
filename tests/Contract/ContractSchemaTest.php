<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Contract;

use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_array;
use function json_decode;
use function preg_match_all;

/**
 * The published-contract gate: every schema under schemas/ is itself a valid JSON-Schema 2020-12
 * document, every shipped example validates against its schema (format asserted), and the OpenAPI's
 * external schema references resolve to files that exist. A faithful lift keeps these green; a typo in
 * a $ref, a broken schema, or a bad example reds it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class ContractSchemaTest extends TestCase
{
    /**
     * The published schemas directory (repo root).
     */
    private const string SCHEMA_DIR = __DIR__ . '/../../schemas';

    /**
     * The shared $id prefix every contract schema (and the example-to-schema mapping) is keyed by.
     */
    private const string ID_PREFIX = 'https://raw.githubusercontent.com/magicsunday/webtrees-obituary-matcher/main/schemas/';

    /**
     * Builds a validator with all three contract schemas registered by their $id so cross-references
     * resolve offline.
     *
     * @return Validator The configured validator.
     */
    private function validator(): Validator
    {
        $validator = new Validator();

        foreach (['capabilities', 'job-request', 'job-response'] as $name) {
            $path = self::SCHEMA_DIR . '/' . $name . '.schema.json';
            $id   = self::ID_PREFIX . $name . '.schema.json';

            // resolver() is typed nullable but is always present on a default validator; the
            // null-safe call keeps the static analysis honest without an ignore.
            $validator->resolver()?->registerFile($id, $path);
        }

        return $validator;
    }

    /**
     * Each contract schema declares the JSON-Schema 2020-12 dialect.
     *
     * opis implements the drafts in code and ships no meta-schema document, so a schema cannot be
     * validated *as data* against the 2020-12 meta-schema URI. This test pins the declared dialect;
     * the structural well-formedness of every keyword is asserted by validExampleValidates() below,
     * whose validate() call compiles the schema under 2020-12 and throws on a malformed construct.
     *
     * @param string $file The schema filename under schemas/.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('schemaFiles')]
    public function schemaDeclares202012Dialect(string $file): void
    {
        // Decode with the `true` flag (never a raw stdClass; house rule) so we can read the dialect key.
        $decoded = json_decode((string) file_get_contents(self::SCHEMA_DIR . '/' . $file), true);

        self::assertTrue(is_array($decoded), $file . ' is not a JSON object');
        self::assertSame(
            'https://json-schema.org/draft/2020-12/schema',
            $decoded['$schema'] ?? null,
            $file . ' does not declare the 2020-12 dialect'
        );
    }

    /**
     * Each shipped example validates against its schema — and the schema compiles cleanly.
     *
     * The validate() call doubles as the structural gate: opis compiles each 2020-12 keyword lazily as
     * it traverses it and raises an InvalidKeywordException on a malformed construct. The valid example
     * carries every property, so every reachable keyword (incl. each $def) is forced to compile; a
     * broken schema therefore reds this case rather than passing silently.
     *
     * @param string $example  The example filename under schemas/examples/.
     * @param string $schemaId The $id of the schema the example must satisfy.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('validExamples')]
    public function validExampleValidates(string $example, string $schemaId): void
    {
        // Helper::toJSON(json_decode(..., true)) hands opis its own JSON data shape and avoids a raw
        // stdClass; the schema is addressed by the $id registered in validator().
        $data   = Helper::toJSON(json_decode((string) file_get_contents(self::SCHEMA_DIR . '/examples/' . $example), true));
        $result = $this->validator()->validate($data, $schemaId);

        self::assertTrue($result->isValid(), $example . ' does not validate against its schema');
    }

    /**
     * The OpenAPI external schema references resolve to files that exist.
     *
     * @return void
     */
    #[Test]
    public function openApiExternalRefsResolve(): void
    {
        $yaml = (string) file_get_contents(self::SCHEMA_DIR . '/obituary-finder.openapi.yaml');

        // Collect EVERY external file ref generically (durable against future schema files), not a
        // hard-coded list: any `$ref: "./<name>.json"` must resolve to a sibling file.
        preg_match_all('#\$ref:\s*["\']\./([A-Za-z0-9._-]+\.json)["\']#', $yaml, $matches);

        self::assertNotEmpty($matches[1], 'expected at least one external ./*.json ref in the OpenAPI');

        foreach ($matches[1] as $file) {
            self::assertFileExists(self::SCHEMA_DIR . '/' . $file, 'OpenAPI references a missing file: ' . $file);
        }

        // The lift moved the OpenAPI next to the schemas, so no ./schemas/ prefix may survive.
        self::assertStringNotContainsString('./schemas/', $yaml, 'OpenAPI still has a stale ./schemas/ ref');
    }

    /**
     * Every malformed fixture is rejected by its schema — the gate has teeth.
     *
     * @param string $fixture  The malformed fixture filename under invalid/.
     * @param string $schemaId The $id of the schema the fixture must fail.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('invalidFixtures')]
    public function invalidFixtureIsRejected(string $fixture, string $schemaId): void
    {
        $data   = Helper::toJSON(json_decode((string) file_get_contents(__DIR__ . '/invalid/' . $fixture), true));
        $result = $this->validator()->validate($data, $schemaId);

        self::assertFalse($result->isValid(), $fixture . ' was accepted but must be rejected');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function invalidFixtures(): array
    {
        return [
            ['unknown-property.response.json', self::ID_PREFIX . 'job-response.schema.json'],
            ['bad-date.response.json', self::ID_PREFIX . 'job-response.schema.json'],
            ['missing-required.request.json', self::ID_PREFIX . 'job-request.schema.json'],
            ['wrong-schema-version.request.json', self::ID_PREFIX . 'job-request.schema.json'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function schemaFiles(): array
    {
        return [['capabilities.schema.json'], ['job-request.schema.json'], ['job-response.schema.json']];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function validExamples(): array
    {
        return [
            ['request.json', self::ID_PREFIX . 'job-request.schema.json'],
            ['response.json', self::ID_PREFIX . 'job-response.schema.json'],
            ['capabilities.json', self::ID_PREFIX . 'capabilities.schema.json'],
        ];
    }
}
