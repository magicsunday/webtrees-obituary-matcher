<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Contract;

use JsonException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function file_get_contents;
use function json_decode;
use function preg_match;
use function preg_match_all;

use const JSON_THROW_ON_ERROR;

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
     * Captures the relative target of an external `*.json` `$ref` in the OpenAPI YAML.
     *
     * Quotes are OPTIONAL on both sides (YAML does not require them on a plain `./file.json` scalar,
     * and a formatter may strip them), an optional `./` prefix and an optional `#fragment` are
     * tolerated, and group 1 is the relative path with the fragment stripped. The path class admits
     * `/`, so a stale subdirectory ref (e.g. a `./schemas/…` that survived the lift) is CAPTURED with
     * its separator rather than silently not matching — the flat-sibling assertion then rejects it.
     * Internal `#/components/...` refs carry no `.json` and never match.
     */
    private const string REF_PATTERN = '#\$ref:\s*["\']?(?:\./)?([A-Za-z0-9._/-]+\.json)(?:\#[^"\'\s]*)?["\']?#';

    /**
     * Builds a validator with all three contract schemas registered by their $id so cross-references
     * resolve offline.
     *
     * @return Validator The configured validator.
     */
    private function validator(): Validator
    {
        $validator = new Validator();

        // resolver() is typed nullable but is always present on a default validator. Assert it once
        // (phpstan-phpunit narrows the type away) so a future null turns into a loud failure here
        // rather than a silent skip that would surface as a confusing "schema not found" downstream.
        $resolver = $validator->resolver();
        self::assertNotNull($resolver, 'opis validator must expose a schema resolver');

        foreach (['capabilities', 'job-request', 'job-response'] as $name) {
            $path = self::SCHEMA_DIR . '/' . $name . '.schema.json';
            $id   = self::ID_PREFIX . $name . '.schema.json';

            $resolver->registerFile($id, $path);
        }

        return $validator;
    }

    /**
     * Loads a JSON document and validates it against the registered schema.
     *
     * Decoded object-faithfully (no `true` flag): opis's native data shape is a stdClass per JSON
     * object and an array per JSON array, so this is the idiomatic opis load form. It preserves the
     * object/array distinction — an empty object `{}` stays an object (the contract allows empty
     * all-optional objects) instead of collapsing to `[]` and falsely failing `type: object`. The
     * decoded value is transient (piped straight into validate(), never stored as a typed stdClass),
     * so the no-stdClass house rule is satisfied. The schema is addressed by the $id registered in
     * validator().
     *
     * @param string $path     The absolute path of the JSON document to validate.
     * @param string $schemaId The $id of the schema to validate against.
     *
     * @return ValidationResult The validation outcome.
     *
     * @throws JsonException If the document is not valid JSON.
     */
    private function loadAndValidate(string $path, string $schemaId): ValidationResult
    {
        return $this->validator()->validate(
            json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR),
            $schemaId
        );
    }

    /**
     * Collects only the CAUSAL (leaf) keywords of an opis error tree.
     *
     * opis nests the failing keyword (format, pattern, const, required, ...) below the structural
     * applicator wrappers (properties, items, $ref, additionalProperties, allOf, if) that merely
     * route into the sub-schema; those wrapper nodes carry further sub-errors, the causal keyword
     * sits on a leaf node whose subErrors() is empty. Collecting only the leaves yields the actual
     * reason the fixture failed, so a per-fixture keyword pin truly discriminates: an unrelated
     * nested failure no longer leaks a structural `properties`/`additionalProperties` into the set
     * and cannot satisfy a wrong pin by accident.
     *
     * @param ValidationError $error The root validation error.
     *
     * @return list<string> The causal (leaf-node) keyword(s) of the error tree.
     */
    private function violatedKeywords(ValidationError $error): array
    {
        $subErrors = $error->subErrors();

        // A node with no sub-errors is the causal leaf: its keyword is the real failure reason.
        if ($subErrors === []) {
            return [$error->keyword()];
        }

        $keywords = [];

        foreach ($subErrors as $subError) {
            // opis types subErrors() only as array; narrow each entry before recursing.
            if (!$subError instanceof ValidationError) {
                continue;
            }

            foreach ($this->violatedKeywords($subError) as $keyword) {
                $keywords[] = $keyword;
            }
        }

        return $keywords;
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
     *
     * @throws JsonException If the schema file is not valid JSON.
     */
    #[Test]
    #[DataProvider('schemaFiles')]
    public function schemaDeclares202012Dialect(string $file): void
    {
        // Decode object-faithfully (no `true` flag), consistent with loadAndValidate().
        $decoded = json_decode((string) file_get_contents(self::SCHEMA_DIR . '/' . $file), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $decoded, $file . ' is not a JSON object');
        self::assertSame(
            'https://json-schema.org/draft/2020-12/schema',
            $decoded->{'$schema'} ?? null,
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
     *
     * @throws JsonException If the example is not valid JSON.
     */
    #[Test]
    #[DataProvider('validExamples')]
    public function validExampleValidates(string $example, string $schemaId): void
    {
        $result = $this->loadAndValidate(self::SCHEMA_DIR . '/examples/' . $example, $schemaId);

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

        // Collect EVERY external *.json file ref generically (durable against future schema files),
        // not a hard-coded list. group 1 is the relative target with the fragment stripped. Internal
        // `#/components/...` refs (no `.json`) never match. See self::REF_PATTERN for the contract.
        preg_match_all(self::REF_PATTERN, $yaml, $matches);

        self::assertNotEmpty($matches[1], 'expected at least one external *.json ref in the OpenAPI');

        foreach ($matches[1] as $file) {
            // The lift moved the OpenAPI next to the schemas, so every external ref must be a FLAT
            // sibling — a surviving subdirectory segment (e.g. a stale `./schemas/…`) is captured
            // with its `/` and rejected here precisely, without forbidding `./schemas/` in prose.
            self::assertStringNotContainsString(
                '/',
                $file,
                'OpenAPI ref must be a flat sibling, got a subdirectory path: ' . $file
            );
            self::assertFileExists(self::SCHEMA_DIR . '/' . $file, 'OpenAPI references a missing file: ' . $file);
        }
    }

    /**
     * The external-ref capture tolerates an unquoted YAML scalar, not only a quoted one.
     *
     * YAML does not require quotes around a plain `./file.json` scalar, and a formatter may strip
     * them. A pattern that only matched the quoted form would silently miss every ref and red the
     * non-emptiness guard (a false failure) the moment the OpenAPI was reformatted. This pins that
     * both spellings capture the same bare filename.
     *
     * @param string $line The `$ref:` YAML line to match.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('refLineSpellings')]
    public function openApiRefPatternToleratesUnquotedYaml(string $line): void
    {
        self::assertSame(
            1,
            preg_match(self::REF_PATTERN, $line, $match),
            'ref pattern did not match: ' . $line
        );
        self::assertSame('capabilities.schema.json', $match[1], 'ref pattern captured the wrong filename');
    }

    /**
     * A stale subdirectory ref is captured WITH its separator, so the flat-sibling guard can catch it.
     *
     * The lift rewrote `./schemas/X` refs to flat `./X` siblings. If a `./schemas/…` ref ever survived,
     * the path-aware capture keeps the `schemas/` segment (rather than silently not matching), and
     * openApiExternalRefsResolve() then rejects any captured ref containing a `/`. This pins that the
     * capture sees the subdirectory path instead of dropping the ref out of the check entirely.
     *
     * @return void
     */
    #[Test]
    public function refPatternCapturesASubdirectoryPathSoTheFlatSiblingGuardCatchesIt(): void
    {
        self::assertSame(
            1,
            preg_match(self::REF_PATTERN, '$ref: "./schemas/job-request.schema.json"', $match),
            'ref pattern did not match a subdirectory ref'
        );
        self::assertSame('schemas/job-request.schema.json', $match[1], 'subdirectory segment was dropped');
        self::assertStringContainsString('/', $match[1], 'a subdirectory ref must keep its separator');
    }

    /**
     * The locale pattern accepts the locale identifiers webtrees actually emits.
     *
     * webtrees ships locales beyond `lang` / `lang-REGION`: script-subtag forms such as `zh-Hans`,
     * `zh-Hant` and `sr-Latn`. The matcher forwards the active webtrees locale verbatim, so a pattern
     * that rejected those would make a legitimate request fail its own published schema. This pins the
     * accept direction for the real webtrees locale set.
     *
     * @param string $locale A locale identifier webtrees can emit.
     *
     * @return void
     *
     * @throws JsonException If the request example is not valid JSON.
     */
    #[Test]
    #[DataProvider('webtreesLocales')]
    public function localeAcceptsWebtreesIdentifiers(string $locale): void
    {
        $request = json_decode(
            (string) file_get_contents(self::SCHEMA_DIR . '/examples/request.json'),
            flags: JSON_THROW_ON_ERROR
        );
        self::assertInstanceOf(stdClass::class, $request, 'request example is not a JSON object');

        $request->locale = $locale;

        $result = $this->validator()->validate($request, self::ID_PREFIX . 'job-request.schema.json');

        self::assertTrue($result->isValid(), 'locale "' . $locale . '" must validate against job-request');
    }

    /**
     * Every malformed fixture is rejected by its schema — and for the EXACT keyword it targets.
     *
     * Asserting only isValid() === false would let a fixture pass for an unrelated reason (a bad-date
     * fixture stays "invalid" even if format assertion silently regressed). Pinning the violated
     * keyword keeps each row a real discriminator of the bound it exercises.
     *
     * @param string $fixture         The malformed fixture filename under invalid/.
     * @param string $schemaId        The $id of the schema the fixture must fail.
     * @param string $expectedKeyword The JSON-Schema keyword whose violation must reject the fixture.
     *
     * @return void
     *
     * @throws JsonException If the fixture is not valid JSON.
     */
    #[Test]
    #[DataProvider('invalidFixtures')]
    public function invalidFixtureIsRejected(string $fixture, string $schemaId, string $expectedKeyword): void
    {
        $result = $this->loadAndValidate(__DIR__ . '/invalid/' . $fixture, $schemaId);

        self::assertFalse($result->isValid(), $fixture . ' was accepted but must be rejected');

        $error = $result->error();
        self::assertNotNull($error, $fixture . ' produced no validation error to inspect');

        // Pin the exact singleton causal-keyword set opis reports for this fixture (not mere
        // membership): a structural keyword leaking into the leaf set, or opis reporting a different
        // causal keyword, reds the test. NB opis short-circuits to one causal branch, so this does
        // not by itself prove the fixture has no second violation; single-violation is maintained by
        // fixture construction (each is a one-mutation copy of a valid example).
        self::assertSame(
            [$expectedKeyword],
            $this->violatedKeywords($error),
            $fixture . ' must be rejected by exactly the "' . $expectedKeyword . '" keyword'
        );
    }

    /**
     * Malformed fixtures, each violating exactly ONE bound, paired with the keyword that must reject it.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidFixtures(): array
    {
        return [
            'unknown property → additionalProperties' => [
                'unknown-property.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'additionalProperties',
            ],
            'unknown notice disposition → enum' => [
                'bad-disposition.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'enum',
            ],
            'relative without confidence → required' => [
                'relative-missing-confidence.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'required',
            ],
            'calendar-invalid date → format' => [
                'bad-date.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'format',
            ],
            'missing required property → required' => [
                'missing-required.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'required',
            ],
            'unknown schema version → const' => [
                'wrong-schema-version.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'const',
            ],
            'over-long date-time fractional seconds → pattern' => [
                'oversized-datetime.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'pattern',
            ],
            'duplicate noticeFields → uniqueItems' => [
                'duplicate-noticefields.capabilities.json',
                self::ID_PREFIX . 'capabilities.schema.json',
                'uniqueItems',
            ],
            'done job without results → required' => [
                'done-without-results.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'required',
            ],
            'empty coverage array → minItems' => [
                'empty-coverage.response.json',
                self::ID_PREFIX . 'job-response.schema.json',
                'minItems',
            ],
            'capabilities without retentionSeconds → required' => [
                'missing-retention.capabilities.json',
                self::ID_PREFIX . 'capabilities.schema.json',
                'required',
            ],
            'malformed locale → pattern' => [
                'bad-locale.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'pattern',
            ],
            'birth without yearRange → required' => [
                'birthspec-without-yearrange.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'required',
            ],
            'duplicate schemaVersions → uniqueItems' => [
                'duplicate-schemaversions.capabilities.json',
                self::ID_PREFIX . 'capabilities.schema.json',
                'uniqueItems',
            ],
            'duplicate portal regions → uniqueItems' => [
                'duplicate-regions.capabilities.json',
                self::ID_PREFIX . 'capabilities.schema.json',
                'uniqueItems',
            ],
            'duplicate request portals → uniqueItems' => [
                'duplicate-portals.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'uniqueItems',
            ],
            'empty name part → minLength' => [
                'empty-name.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'minLength',
            ],
            'duplicate excludedHosts → uniqueItems' => [
                'duplicate-excludedhosts.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'uniqueItems',
            ],
            'too many excludedHosts → maxItems' => [
                'too-many-excludedhosts.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'maxItems',
            ],
            'over-long excludedHost → maxLength' => [
                'oversized-excludedhost.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'maxLength',
            ],
            'excludedHost with whitespace → pattern' => [
                'bad-excludedhost.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'pattern',
            ],
            'excludedHost carrying a scheme/path → pattern' => [
                'scheme-excludedhost.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'pattern',
            ],
            'excludedHost with uppercase → pattern' => [
                'uppercase-excludedhost.request.json',
                self::ID_PREFIX . 'job-request.schema.json',
                'pattern',
            ],
        ];
    }

    /**
     * The quoted and unquoted YAML spellings of one external `$ref`, both of which must capture.
     *
     * @return array<string, array{0: string}>
     */
    public static function refLineSpellings(): array
    {
        return [
            'double-quoted' => ['                                $ref: "./capabilities.schema.json"'],
            'single-quoted' => ["                                \$ref: './capabilities.schema.json'"],
            'unquoted'      => ['                                $ref: ./capabilities.schema.json'],
        ];
    }

    /**
     * Locale identifiers the pattern admits — every accept BRANCH covered so a re-tightening reds.
     *
     * The pattern `^[a-z]{2,3}(-[A-Z][a-z]{3})?(-(?:[A-Z]{2}|[0-9]{3}))?$` widens the historical
     * `lang(-REGION)` shape on forward-compatible axes: a 3-letter primary language (BCP-47 / ISO-639-3,
     * as webtrees ships e.g. `gsw`), a script subtag, and a region that is either an ISO-3166-1 letter
     * code OR a UN M.49 three-digit code (the localisation library ships `Es419`/`En001`/`En150`).
     * Every accept branch — bare language, 3-letter language, +letter-region, +numeric-region, +script,
     * and script+region together — is exercised by at least one row, so dropping the `{2,3}`, the
     * numeric-region alternative, or either optional group from the pattern reds the suite rather than
     * passing silently. The three +script rows `sr-Latn`/`zh-Hans`/`zh-Hant` share one branch on
     * purpose: they document the real webtrees script-subtag locale set.
     *
     * @return array<string, array{0: string}>
     */
    public static function webtreesLocales(): array
    {
        return [
            'language only'              => ['de'],
            'three-letter language'      => ['gsw'],
            'language + region'          => ['en-AU'],
            'language + numeric region'  => ['es-419'],
            'language + script'          => ['sr-Latn'],
            'chinese simplified'         => ['zh-Hans'],
            'chinese traditional'        => ['zh-Hant'],
            'language + script + region' => ['zh-Hant-TW'],
        ];
    }

    /**
     * The contract schema filenames whose declared dialect is pinned.
     *
     * @return array<string, array{0: string}>
     */
    public static function schemaFiles(): array
    {
        return [
            'capabilities schema' => ['capabilities.schema.json'],
            'job-request schema'  => ['job-request.schema.json'],
            'job-response schema' => ['job-response.schema.json'],
        ];
    }

    /**
     * Each shipped example paired with the $id of the schema it must satisfy.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validExamples(): array
    {
        return [
            'request example → job-request'       => ['request.json', self::ID_PREFIX . 'job-request.schema.json'],
            'response example → job-response'     => ['response.json', self::ID_PREFIX . 'job-response.schema.json'],
            'capabilities example → capabilities' => ['capabilities.json', self::ID_PREFIX . 'capabilities.schema.json'],
            // A `done` job that SEARCHED a person but found nothing represents that person as a
            // PRESENT key whose PersonResult carries empty `notices` plus a NON-empty `coverage` of
            // one entry with status "ok"/noticeCount 0 — the "real miss" shape the contract exists to
            // capture (distinct from a missing key = "not searched"). This row pins that SEMANTIC: a
            // valid example with a 1-item coverage cannot pin the lower `minItems: 1` bound (dropping
            // it leaves this green); that bound is pinned in the reject direction by the
            // empty-coverage.response.json invalid fixture.
            'empty-results response → job-response' => [
                'response-empty-results.json',
                self::ID_PREFIX . 'job-response.schema.json',
            ],
            // The ELSE branch of the `done` → required:results conditional: a non-terminal job
            // (queued/running/failed) may legitimately OMIT `results`. This row locks that branch —
            // a regression that hoists `results` into the ROOT `required` (making it unconditional)
            // would still pass every `done` example but reds this one, catching the silently broken
            // polling wire-shape (the matcher receives running/queued responses while polling).
            'running response without results → job-response' => [
                'response-running.json',
                self::ID_PREFIX . 'job-response.schema.json',
            ],
        ];
    }
}
