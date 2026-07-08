<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Support\FinderConnectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure config-to-connection resolver shared by the admin control-panel handler and the
 * headless CLI adapters: only the explicit `finder_transport === 'rest'` consent marker with a valid,
 * non-empty base URL yields a connection; a non-rest transport, an empty base URL and a base URL the
 * {@see FinderConnection::rest()} source rejects all resolve to null; and an empty token yields an
 * unauthenticated connection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FinderConnectionResolver::class)]
#[UsesClass(FinderConnection::class)]
final class FinderConnectionResolverTest extends TestCase
{
    /**
     * A transport other than 'rest' (the legacy 'file' consent value) resolves to null even when a valid
     * base URL is stored: the retained REST creds are never silently reactivated.
     *
     * @return void
     */
    #[Test]
    public function aNonRestTransportResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('file', 'https://finder.example', 'secret'),
        );
    }

    /**
     * The unset-default (empty) transport resolves to null: REST activates only on explicit consent.
     *
     * @return void
     */
    #[Test]
    public function anEmptyTransportResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('', 'https://finder.example', 'secret'),
        );
    }

    /**
     * A rest transport with an empty base URL resolves to null: an unconfigured base URL is "not
     * configured".
     *
     * @return void
     */
    #[Test]
    public function anEmptyBaseUrlResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('rest', '', 'secret'),
        );
    }

    /**
     * A base URL the {@see FinderConnection::rest()} source rejects (a non-http(s) scheme) resolves to
     * null rather than escaping as an exception.
     *
     * @return void
     */
    #[Test]
    public function aRejectedBaseUrlResolvesToNull(): void
    {
        self::assertNull(
            FinderConnectionResolver::fromConfig('rest', 'ftp://x', 'secret'),
        );
    }

    /**
     * A valid rest config resolves to a connection carrying the stored base URL and token.
     *
     * @return void
     */
    #[Test]
    public function aValidRestConfigResolvesToAConnectionCarryingTheBaseUrlAndToken(): void
    {
        $connection = FinderConnectionResolver::fromConfig('rest', 'https://finder.example', 'secret-token');

        self::assertInstanceOf(FinderConnection::class, $connection);
        self::assertSame('https://finder.example', $connection->baseUrl());
        self::assertSame('secret-token', $connection->token());
    }

    /**
     * An empty stored token yields a connection with a null token (a blank preference is not a token).
     *
     * @return void
     */
    #[Test]
    public function anEmptyTokenResolvesToAConnectionWithoutAToken(): void
    {
        $connection = FinderConnectionResolver::fromConfig('rest', 'https://finder.example', '');

        self::assertInstanceOf(FinderConnection::class, $connection);
        self::assertNull($connection->token());
    }

    /**
     * Without the REST consent marker the connection list is empty even when additional finders are
     * configured — the same consent gate the single connection uses.
     *
     * @return void
     */
    #[Test]
    public function listResolvesToEmptyWithoutRestConsent(): void
    {
        $list = FinderConnectionResolver::listFromConfig(
            'file',
            'https://finder.example',
            'secret',
            '[{"baseUrl":"https://extra.example","active":true}]',
        );

        self::assertSame([], $list);
    }

    /**
     * A single-finder install (no additional finders) resolves to exactly the primary connection.
     *
     * @return void
     */
    #[Test]
    public function listResolvesToThePrimaryConnectionAloneWhenNoAdditionalFindersAreConfigured(): void
    {
        $list = FinderConnectionResolver::listFromConfig('rest', 'https://finder.example', 'secret', '');

        self::assertCount(1, $list);
        self::assertSame('https://finder.example', $list[0]->baseUrl());
    }

    /**
     * An active, valid additional finder is appended after the primary, in order.
     *
     * @return void
     */
    #[Test]
    public function listAppendsAnActiveAdditionalFinderAfterThePrimary(): void
    {
        $list = FinderConnectionResolver::listFromConfig(
            'rest',
            'https://primary.example',
            'primary-token',
            '[{"baseUrl":"https://extra.example","token":"extra-token","active":true}]',
        );

        self::assertCount(2, $list);
        self::assertSame('https://primary.example', $list[0]->baseUrl());
        self::assertSame('https://extra.example', $list[1]->baseUrl());
        self::assertSame('extra-token', $list[1]->token());
    }

    /**
     * An inactive additional finder is skipped: only its `active === true` sibling is composed.
     *
     * @return void
     */
    #[Test]
    public function listSkipsAnInactiveAdditionalFinder(): void
    {
        $list = FinderConnectionResolver::listFromConfig(
            'rest',
            'https://primary.example',
            '',
            '[{"baseUrl":"https://off.example","active":false},{"baseUrl":"https://on.example","active":true}]',
        );

        self::assertCount(2, $list);
        self::assertSame('https://primary.example', $list[0]->baseUrl());
        self::assertSame('https://on.example', $list[1]->baseUrl());
    }

    /**
     * A malformed additional finder (an invalid base URL, or a corrupt JSON document) is dropped without
     * suppressing the primary or the other valid entries.
     *
     * @return void
     */
    #[Test]
    public function listDropsAMalformedAdditionalFinderButKeepsThePrimary(): void
    {
        $invalidUrl = FinderConnectionResolver::listFromConfig(
            'rest',
            'https://primary.example',
            '',
            '[{"baseUrl":"ftp://nope","active":true},{"baseUrl":"https://ok.example","active":true}]',
        );

        self::assertCount(2, $invalidUrl);
        self::assertSame('https://primary.example', $invalidUrl[0]->baseUrl());
        self::assertSame('https://ok.example', $invalidUrl[1]->baseUrl());

        $corruptJson = FinderConnectionResolver::listFromConfig('rest', 'https://primary.example', '', '{not json');

        self::assertCount(1, $corruptJson);
        self::assertSame('https://primary.example', $corruptJson[0]->baseUrl());
    }

    /**
     * When the primary base URL is unset but an active additional finder is valid, the list is exactly
     * that additional finder — the additional finders are first-class, not merely a supplement.
     *
     * @return void
     */
    #[Test]
    public function listResolvesToAnAdditionalFinderWhenThePrimaryIsUnset(): void
    {
        $list = FinderConnectionResolver::listFromConfig(
            'rest',
            '',
            '',
            '[{"baseUrl":"https://only.example","active":true}]',
        );

        self::assertCount(1, $list);
        self::assertSame('https://only.example', $list[0]->baseUrl());
    }

    /**
     * A duplicate base URL is dropped so each resolved connection is distinct — the invariant the
     * per-finder ledger namespacing relies on. An additional finder repeating the primary's URL, and two
     * additional finders sharing a URL, both collapse to a single connection for that URL.
     *
     * @return void
     */
    #[Test]
    public function listDropsAnAdditionalFinderThatDuplicatesAnEarlierBaseUrl(): void
    {
        $dupOfPrimary = FinderConnectionResolver::listFromConfig(
            'rest',
            'https://finder.example',
            'primary-token',
            '[{"baseUrl":"https://finder.example","token":"other-token","active":true}]',
        );

        self::assertCount(1, $dupOfPrimary);
        self::assertSame('https://finder.example', $dupOfPrimary[0]->baseUrl());
        self::assertSame('primary-token', $dupOfPrimary[0]->token());

        $dupAdditionals = FinderConnectionResolver::listFromConfig(
            'rest',
            '',
            '',
            '[{"baseUrl":"https://x.example","active":true},{"baseUrl":"https://x.example","active":true}]',
        );

        self::assertCount(1, $dupAdditionals);
        self::assertSame('https://x.example', $dupAdditionals[0]->baseUrl());
    }

    /**
     * Every defensive drop branch of the additional-finders decoder rejects the malformed entry while the
     * primary survives — a corrupt `finder_additional` preference can never crash the resolution or
     * suppress the configured primary.
     *
     * @param string $additionalJson The malformed additional-finders JSON.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('malformedAdditionalProvider')]
    public function listDropsAMalformedAdditionalEntryButKeepsThePrimary(string $additionalJson): void
    {
        $list = FinderConnectionResolver::listFromConfig('rest', 'https://primary.example', '', $additionalJson);

        self::assertCount(1, $list);
        self::assertSame('https://primary.example', $list[0]->baseUrl());
    }

    /**
     * Malformed `finder_additional` documents/rows the decoder must drop — one row per defensive branch.
     *
     * @return array<string, array{string}>
     */
    public static function malformedAdditionalProvider(): array
    {
        return [
            'top-level not a list'       => ['5'],
            'top-level a JSON string'    => ['"https://x.example"'],
            'row is not an object'       => ['[1]'],
            'row without a base URL'     => ['[{"active":true}]'],
            'row with non-string base'   => ['[{"baseUrl":5,"active":true}]'],
            'row with an empty base'     => ['[{"baseUrl":"","active":true}]'],
            'truthy-but-non-bool active' => ['[{"baseUrl":"https://x.example","active":1}]'],
        ];
    }
}
