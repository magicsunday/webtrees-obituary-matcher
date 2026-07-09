<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\CorruptMatchRowException;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the origin-finder serialisation of {@see StoredMatch} (§5.2f): the field round-trips through the
 * on-disk shape, a legacy row written before §5.2f (no `originFinderId` key) migrates to null rather than
 * being rejected, and a corrupt (non-string) origin is refused.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
final class StoredMatchTest extends TestCase
{
    /**
     * Builds a minimal valid classified-match payload for the row shape.
     *
     * @return ClassifiedMatchArray
     */
    private static function payload(): array
    {
        return [
            'personId'        => 'I1',
            'obituaryUrl'     => 'https://example.test/a',
            'score'           => 0,
            'hardConflict'    => false,
            'signals'         => [],
            'extractedFacts'  => [],
            'noticeRelatives' => [],
            'classification'  => 'weak',
            'ambiguous'       => false,
            'runnerUp'        => null,
            'review'          => null,
        ];
    }

    /**
     * The origin finder survives a toArray/fromArray round-trip.
     *
     * @return void
     */
    #[Test]
    public function theOriginFinderRoundTrips(): void
    {
        $match = new StoredMatch(
            'I1',
            'https://example.test/a',
            MatchStatus::Pending,
            self::payload(),
            originFinderId: 'https://finder.example',
        );

        $restored = StoredMatch::fromArray($match->toArray());

        self::assertSame('https://finder.example', $restored->originFinderId);
    }

    /**
     * A legacy row written before §5.2f carries no `originFinderId` key; it migrates to null rather than
     * being rejected as corrupt.
     *
     * @return void
     */
    #[Test]
    public function aLegacyRowWithoutAnOriginFinderMigratesToNull(): void
    {
        $row = [
            'personId'    => 'I1',
            'obituaryUrl' => 'https://example.test/a',
            'status'      => MatchStatus::Pending->value,
            'match'       => self::payload(),
            'reason'      => null,
            'writeBack'   => null,
        ];

        self::assertNull(StoredMatch::fromArray($row)->originFinderId);
    }

    /**
     * A non-string origin finder is refused rather than silently coerced.
     *
     * @return void
     */
    #[Test]
    public function aNonStringOriginFinderIsRejected(): void
    {
        $row = [
            'personId'       => 'I1',
            'obituaryUrl'    => 'https://example.test/a',
            'status'         => MatchStatus::Pending->value,
            'match'          => self::payload(),
            'reason'         => null,
            'writeBack'      => null,
            'originFinderId' => 42,
        ];

        $this->expectException(CorruptMatchRowException::class);

        StoredMatch::fromArray($row);
    }
}
