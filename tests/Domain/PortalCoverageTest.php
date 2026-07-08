<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Domain;

use MagicSunday\ObituaryMatcher\Domain\CoverageStatus;
use MagicSunday\ObituaryMatcher\Domain\PortalCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the coverage value object's persistence serialisation: a round-trip through toArray/fromArray
 * preserves every field, and a corrupt stored row rebuilds to null rather than a broken object.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PortalCoverage::class)]
#[UsesClass(CoverageStatus::class)]
final class PortalCoverageTest extends TestCase
{
    /**
     * A full entry round-trips through toArray/fromArray unchanged.
     *
     * @return void
     */
    #[Test]
    public function roundTripsThroughToArrayAndFromArray(): void
    {
        $coverage = new PortalCoverage('freiepresse', CoverageStatus::Failed, 3, 'timeout');

        $rebuilt = PortalCoverage::fromArray($coverage->toArray());

        self::assertEquals($coverage, $rebuilt);
    }

    /**
     * A null noticeCount / message round-trips as null.
     *
     * @return void
     */
    #[Test]
    public function roundTripsTheOptionalNulls(): void
    {
        $coverage = new PortalCoverage('trauer_anzeigen', CoverageStatus::Ok, null, null);

        $rebuilt = PortalCoverage::fromArray($coverage->toArray());

        self::assertEquals($coverage, $rebuilt);
    }

    /**
     * A corrupt stored row (unknown status, empty/absent portal) rebuilds to null so the reader can drop
     * it rather than construct a broken value.
     *
     * @return void
     */
    #[Test]
    public function fromArrayReturnsNullForACorruptRow(): void
    {
        self::assertNull(PortalCoverage::fromArray(['portal' => 'a', 'status' => 'bogus']));
        self::assertNull(PortalCoverage::fromArray(['portal' => '', 'status' => 'ok']));
        self::assertNull(PortalCoverage::fromArray(['status' => 'ok']));
    }
}
