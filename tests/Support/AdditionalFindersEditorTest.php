<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Support\AdditionalFindersEditor;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the pure control-panel editor that turns the submitted additional-finder rows into the canonical
 * `finder_additional` JSON (§5.2f increment 2): all-or-nothing validation, token-keep-by-base-URL-identity,
 * blank-row skipping and within-form / reserved dedup — the webtrees-free write counterpart of
 * {@see \MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver::listFromConfig()} which reads it back.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(AdditionalFindersEditor::class)]
#[UsesClass(FinderConnection::class)]
final class AdditionalFindersEditorTest extends TestCase
{
    /**
     * Builds one submitted row with the given fields, defaulting the flags a blank UI row carries.
     *
     * @param string $baseUrl     The submitted base URL.
     * @param string $token       The submitted token field (empty means blank).
     * @param bool   $active      Whether the active toggle is on.
     * @param bool   $removeToken The remove-token flag.
     *
     * @return array{baseUrl: string, token: string, active: bool, removeToken: bool}
     */
    private static function row(
        string $baseUrl,
        string $token = '',
        bool $active = true,
        bool $removeToken = false,
    ): array {
        return [
            'baseUrl'     => $baseUrl,
            'token'       => $token,
            'active'      => $active,
            'removeToken' => $removeToken,
        ];
    }

    /**
     * Decodes the editor output back into an assoc list so the tests can assert on the canonical shape.
     *
     * @param string $json The editor output.
     *
     * @return list<array<string, string|bool>>
     */
    private static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        /** @var list<array<string, string|bool>> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * An empty submitted list (no rows) yields the empty preference — "no additional finders configured".
     *
     * @return void
     */
    #[Test]
    public function anEmptySubmissionYieldsTheEmptyPreference(): void
    {
        self::assertSame('', AdditionalFindersEditor::toJson([], ''));
    }

    /**
     * A row whose base URL is blank is a trailing/unused UI row and is skipped, not rejected — so an
     * admin who leaves the "add finder" row empty still saves cleanly.
     *
     * @return void
     */
    #[Test]
    public function aRowWithABlankBaseUrlIsSkipped(): void
    {
        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'tok-a'),
            self::row(''),
        ], '');

        $rows = self::decode($json);

        self::assertCount(1, $rows);
        self::assertSame('https://a.example', $rows[0]['baseUrl']);
    }

    /**
     * A valid active row with a typed token is stored with its base URL, token and active flag.
     *
     * @return void
     */
    #[Test]
    public function aTypedTokenIsStored(): void
    {
        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'typed-token', true),
        ], '');

        $rows = self::decode($json);

        self::assertCount(1, $rows);
        self::assertSame('https://a.example', $rows[0]['baseUrl']);
        self::assertSame('typed-token', $rows[0]['token']);
        self::assertTrue($rows[0]['active']);
    }

    /**
     * An inactive row is still persisted (with its token) so toggling it back on does not lose the token.
     *
     * @return void
     */
    #[Test]
    public function anInactiveRowIsStoredWithItsToken(): void
    {
        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'tok-a', false),
        ], '');

        $rows = self::decode($json);

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['active']);
        self::assertSame('tok-a', $rows[0]['token']);
    }

    /**
     * A blank token field keeps the token already stored for the SAME finder (matched by base-URL
     * identity), so a settings save that does not re-enter the secret does not wipe it.
     *
     * @return void
     */
    #[Test]
    public function aBlankTokenKeepsTheStoredTokenOfTheSameFinder(): void
    {
        $existing = '[{"baseUrl":"https://a.example","token":"stored-token","active":true}]';

        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', '', true),
        ], $existing);

        $rows = self::decode($json);

        self::assertSame('stored-token', $rows[0]['token']);
    }

    /**
     * The token-keep match is by base-URL IDENTITY: a trailing-slash variant of the stored URL still
     * keeps the stored token (the same normalization the resolver dedups on).
     *
     * @return void
     */
    #[Test]
    public function aBlankTokenKeepsTheStoredTokenAcrossATrailingSlashVariant(): void
    {
        $existing = '[{"baseUrl":"https://a.example","token":"stored-token","active":true}]';

        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example/', '', true),
        ], $existing);

        $rows = self::decode($json);

        self::assertSame('stored-token', $rows[0]['token']);
    }

    /**
     * A blank token with no stored token for that finder yields an unauthenticated connection: no token
     * key is emitted (the resolver reads an absent token as "no token").
     *
     * @return void
     */
    #[Test]
    public function aBlankTokenWithoutAStoredMatchYieldsNoToken(): void
    {
        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', '', true),
        ], '');

        $rows = self::decode($json);

        self::assertArrayNotHasKey('token', $rows[0]);
    }

    /**
     * A typed token overrides the stored token of the same finder.
     *
     * @return void
     */
    #[Test]
    public function aTypedTokenOverridesTheStoredToken(): void
    {
        $existing = '[{"baseUrl":"https://a.example","token":"old","active":true}]';

        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'new', true),
        ], $existing);

        $rows = self::decode($json);

        self::assertSame('new', $rows[0]['token']);
    }

    /**
     * The remove-token flag clears the stored token even when the field is blank (remove wins over keep).
     *
     * @return void
     */
    #[Test]
    public function theRemoveFlagClearsTheStoredToken(): void
    {
        $existing = '[{"baseUrl":"https://a.example","token":"stored","active":true}]';

        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', '', true, true),
        ], $existing);

        $rows = self::decode($json);

        self::assertArrayNotHasKey('token', $rows[0]);
    }

    /**
     * An invalid base URL rejects the WHOLE save (all-or-nothing) with the 1-based row position in the
     * message, so nothing is partially persisted and the admin can find the offending row.
     *
     * @return void
     */
    #[Test]
    public function anInvalidRowRejectsTheWholeSaveNamingTheRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\b2\b/');

        AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'tok-a'),
            self::row('not a url', 'tok-b'),
        ], '');
    }

    /**
     * Two submitted rows with the same base-URL identity (here a trailing-slash variant) reject the save:
     * each finder must be distinct, the invariant the per-finder ledger namespacing relies on.
     *
     * @return void
     */
    #[Test]
    public function aDuplicateBaseUrlWithinTheFormRejectsTheSave(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'tok-a'),
            self::row('https://a.example/', 'tok-b'),
        ], '');
    }

    /**
     * A row whose base-URL identity collides with a reserved key (the primary connection) rejects the
     * save, so an additional finder can never duplicate the primary.
     *
     * @return void
     */
    #[Test]
    public function aRowCollidingWithAReservedKeyRejectsTheSave(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalFindersEditor::toJson(
            [self::row('https://primary.example/', 'tok')],
            '',
            ['https://primary.example'],
        );
    }

    /**
     * A control-character token is rejected at the single {@see FinderConnection::rest()} source, so a
     * malformed secret cannot be persisted.
     *
     * @return void
     */
    #[Test]
    public function aControlCharacterTokenRejectsTheSave(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalFindersEditor::toJson([
            self::row('https://a.example', "tok\nb"),
        ], '');
    }

    /**
     * A token carrying an invalid-UTF-8 byte sequence survives the control-character check at
     * {@see FinderConnection::rest()} but cannot be JSON-encoded; the whole save is rejected rather than
     * a corrupt preference emitted.
     *
     * @return void
     */
    #[Test]
    public function aTokenThatCannotBeEncodedRejectsTheSave(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalFindersEditor::toJson([
            self::row('https://a.example', "tok\xC3\x28"),
        ], '');
    }

    /**
     * Multiple valid rows are stored in submission order, each with its own resolved token.
     *
     * @return void
     */
    #[Test]
    public function multipleValidRowsAreStoredInOrder(): void
    {
        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', 'tok-a', true),
            self::row('https://b.example', '', false),
        ], '');

        $rows = self::decode($json);

        self::assertCount(2, $rows);
        self::assertSame('https://a.example', $rows[0]['baseUrl']);
        self::assertSame('https://b.example', $rows[1]['baseUrl']);
        self::assertFalse($rows[1]['active']);
    }

    /**
     * The stored preference projects to display rows carrying EVERY finder (active and inactive), each
     * with its base URL, a token-is-set boolean (never the value) and its active flag — the control panel
     * must show every configured finder so the admin can edit or re-activate it.
     *
     * @return void
     */
    #[Test]
    public function storedRowsProjectEveryFinderWithTokenIsSetAndActive(): void
    {
        $json = '[{"baseUrl":"https://a.example","token":"secret","active":true},'
            . '{"baseUrl":"https://b.example","active":false}]';

        $rows = AdditionalFindersEditor::storedRows($json);

        self::assertCount(2, $rows);

        self::assertSame('https://a.example', $rows[0]['baseUrl']);
        self::assertTrue($rows[0]['tokenIsSet']);
        self::assertTrue($rows[0]['active']);

        self::assertSame('https://b.example', $rows[1]['baseUrl']);
        self::assertFalse($rows[1]['tokenIsSet']);
        self::assertFalse($rows[1]['active']);
    }

    /**
     * Every defensive drop-branch of the shared decoder is tolerated as an empty projection rather than
     * crashing the render: an empty preference, invalid JSON, a non-list document, a non-object row, a row
     * without a base URL, and a row with an empty base URL are each dropped.
     *
     * @param string $json The preference value to project.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('emptyStoredRowsProvider')]
    public function storedRowsAreEmptyForAnEmptyOrCorruptPreference(string $json): void
    {
        self::assertSame([], AdditionalFindersEditor::storedRows($json));
    }

    /**
     * The preference values the display decoder must drop to an empty projection — one row per defensive
     * branch of decodeStored().
     *
     * @return array<string, array{string}>
     */
    public static function emptyStoredRowsProvider(): array
    {
        return [
            'empty string'               => [''],
            'invalid JSON'               => ['{not json'],
            'non-list document'          => ['123'],
            'non-object row'             => ['[123]'],
            'row without a base URL'     => ['[{"active":true}]'],
            'row with an empty base URL' => ['[{"baseUrl":"","active":true}]'],
        ];
    }

    /**
     * A stored additional finder whose token is an empty string contributes no token to the keep-map, so a
     * blank submitted token for that finder resolves to an unauthenticated connection rather than an empty
     * token — pinning the empty-token skip branch of the keep lookup.
     *
     * @return void
     */
    #[Test]
    public function aBlankTokenDoesNotKeepAnEmptyStoredToken(): void
    {
        $existing = '[{"baseUrl":"https://a.example","token":"","active":true}]';

        $json = AdditionalFindersEditor::toJson([
            self::row('https://a.example', '', true),
        ], $existing);

        $rows = self::decode($json);

        self::assertArrayNotHasKey('token', $rows[0]);
    }
}
