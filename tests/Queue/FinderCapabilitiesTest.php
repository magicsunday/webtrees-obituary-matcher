<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\FinderCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinderCapabilities::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Queue\FinderPortal::class)]
final class FinderCapabilitiesTest extends TestCase
{
    /** A well-formed body narrows to a populated VO. */
    #[Test]
    public function aWellFormedBodyNarrows(): void
    {
        $caps = FinderCapabilities::fromArray([
            'finderId'         => 'finder-1',
            'finderVersion'    => '1.0.0',
            'retentionSeconds' => 86_400,
            'schemaVersions'   => [1, 1, 2],
            'portals'          => [['id' => 'p-de', 'name' => 'P (DE)', 'country' => 'DE', 'regions' => ['R1']]],
            'noticeFields'     => ['death', 'relatives', 'unknown-future-field'],
            'features'         => ['pagination' => true, 'bogus' => 'x'],
        ]);

        self::assertNotNull($caps);
        self::assertSame('finder-1', $caps->finderId);
        self::assertSame([1, 2], $caps->schemaVersions);                 // de-duped
        self::assertSame(['death', 'relatives'], $caps->noticeFields);    // unknown dropped
        self::assertSame(['pagination' => true], $caps->features);        // non-bool dropped
        self::assertCount(1, $caps->portals);
        self::assertSame('p-de', $caps->portals[0]->id);
    }

    /** A missing required key (finderId) is invalid → null. */
    #[Test]
    public function aMissingRequiredKeyIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'retentionSeconds' => 10,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
        ]));
    }

    /** noticeFields present but not a string list → invalid (wrong shape, not a drop). */
    #[Test]
    public function aNonStringListNoticeFieldsIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
            'noticeFields'     => 'death',
        ]));
    }

    /** No valid portal survives (all ids fail the pattern) → invalid. */
    #[Test]
    public function noValidPortalIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'NOT VALID']],
        ]));
    }

    /** schemaVersions is TOLERANT: a bad element is DROPPED (not invalid) as long as one valid int survives. */
    #[Test]
    public function aBadSchemaVersionEntryIsDroppedNotInvalid(): void
    {
        $caps = FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => [1, 'x', 2, 9999],   // 'x' non-int + 9999 out-of-range → dropped
            'portals'          => [['id' => 'p']],
        ]);

        self::assertNotNull($caps);
        self::assertSame([1, 2], $caps->schemaVersions);
    }

    /** noticeFields is STRICTER than schemaVersions: a non-string ELEMENT means a corrupt shape → invalid. */
    #[Test]
    public function aNonStringNoticeFieldElementIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
            'noticeFields'     => ['death', 123],   // 123 is not a string → invalid (not a drop)
        ]));
    }

    /** features: only string-keyed boolean flags are kept; an integer key or a non-bool value is dropped. */
    #[Test]
    public function featuresKeepsOnlyStringKeyedBooleans(): void
    {
        $caps = FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
            'features'         => ['pagination' => true, 0 => true, 'queryHints' => 'yes'],
        ]);

        self::assertNotNull($caps);
        self::assertSame(['pagination' => true], $caps->features);   // int key 0 + non-bool 'yes' dropped
    }

    /** retentionSeconds is a REQUIRED bounded field: a zero (below the floor) or a wrong type invalidates the document. */
    #[Test]
    public function aRetentionSecondsOutOfRangeIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 0,                 // below the inclusive 1 floor
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
        ]));

        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => '86400',           // wrong type (string, not int)
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
        ]));
    }

    /** schemaVersions is tolerant per-element but the FIELD is required: an all-dropped list (no surviving int) invalidates the document. */
    #[Test]
    public function aSchemaVersionsWithNoSurvivingIntIsInvalid(): void
    {
        self::assertNull(FinderCapabilities::fromArray([
            'finderId'         => 'f',
            'retentionSeconds' => 10,
            'schemaVersions'   => ['x', 9999],       // 'x' non-int + 9999 out-of-range → none survive
            'portals'          => [['id' => 'p']],
        ]));
    }
}
