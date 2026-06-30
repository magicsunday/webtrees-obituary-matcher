<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\CapabilitiesProbeResult;
use MagicSunday\ObituaryMatcher\Queue\FinderCapabilities;
use MagicSunday\ObituaryMatcher\Queue\FinderPortal;
use MagicSunday\ObituaryMatcher\Queue\ProbeStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the capabilities probe result value object — the four named constructors and the
 * status/capabilities readout the admin control panel renders after probing a finder connection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CapabilitiesProbeResult::class)]
#[UsesClass(FinderCapabilities::class)]
#[UsesClass(FinderPortal::class)]
final class CapabilitiesProbeResultTest extends TestCase
{
    /**
     * Builds a minimal valid capabilities value object for the reachable case.
     *
     * @return FinderCapabilities The narrowed capabilities.
     */
    private function caps(): FinderCapabilities
    {
        $caps = FinderCapabilities::tryFromArray([
            'finderId'         => 'finder-1',
            'retentionSeconds' => 86_400,
            'schemaVersions'   => [1],
            'portals'          => [['id' => 'p']],
        ]);

        self::assertNotNull($caps);

        return $caps;
    }

    /** Each named constructor sets the matching status and only its own optional fields. */
    #[Test]
    public function namedConstructorsSetTheStatus(): void
    {
        $caps = $this->caps();

        $reachable = CapabilitiesProbeResult::reachable($caps);
        self::assertSame(ProbeStatus::Reachable, $reachable->status);
        self::assertSame($caps, $reachable->capabilities);
        self::assertNull($reachable->httpStatus);

        $unreachable = CapabilitiesProbeResult::unreachable(503);
        self::assertSame(ProbeStatus::Unreachable, $unreachable->status);
        self::assertSame(503, $unreachable->httpStatus);
        self::assertNull($unreachable->capabilities);

        self::assertSame(ProbeStatus::Invalid, CapabilitiesProbeResult::invalid()->status);
        self::assertNull(CapabilitiesProbeResult::invalid()->capabilities);
        self::assertNull(CapabilitiesProbeResult::invalid()->httpStatus);

        $notApplicable = CapabilitiesProbeResult::notApplicable();
        self::assertSame(ProbeStatus::NotApplicable, $notApplicable->status);
        self::assertNull($notApplicable->capabilities);
        self::assertNull($notApplicable->httpStatus);
    }

    /** The unreachable constructor defaults the HTTP status to null. */
    #[Test]
    public function unreachableDefaultsTheHttpStatusToNull(): void
    {
        self::assertNull(CapabilitiesProbeResult::unreachable()->httpStatus);
    }

    /** The constructor is not publicly callable so only the named constructors may build the VO. */
    #[Test]
    public function theConstructorIsPrivate(): void
    {
        self::assertTrue(
            (new ReflectionMethod(CapabilitiesProbeResult::class, '__construct'))->isPrivate()
        );
    }
}
