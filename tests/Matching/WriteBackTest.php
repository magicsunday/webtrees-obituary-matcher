<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the write-back persistence record.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(WriteBack::class)]
final class WriteBackTest extends TestCase
{
    /**
     * The 2d-3a write-back round-trips with the reserved buriFactId=null and citationIds=[].
     *
     * @return void
     */
    #[Test]
    public function roundTripsWithReservedFields(): void
    {
        $writeBack = new WriteBack('deat-1', 'S123', true);

        self::assertSame([
            'deatFactId'    => 'deat-1',
            'buriFactId'    => null,
            'cremFactId'    => null,
            'sourceXref'    => 'S123',
            'sourceCreated' => true,
            'citationIds'   => [],
        ], $writeBack->toArray());

        $restored = WriteBack::fromArray($writeBack->toArray());

        self::assertSame('deat-1', $restored->deatFactId);
        self::assertSame('S123', $restored->sourceXref);
        self::assertTrue($restored->sourceCreated);
        self::assertNull($restored->buriFactId);
        self::assertNull($restored->cremFactId);
        self::assertSame([], $restored->citationIds);
    }

    /**
     * A cremation write-back round-trips its cremFactId (with buriFactId null — the two are mutually
     * exclusive), so a later Revert can resolve the written CREM.
     *
     * @return void
     */
    #[Test]
    public function roundTripsACremationWriteBack(): void
    {
        $writeBack = new WriteBack('deat-1', 'S123', false, null, 'crem-9');

        self::assertSame([
            'deatFactId'    => 'deat-1',
            'buriFactId'    => null,
            'cremFactId'    => 'crem-9',
            'sourceXref'    => 'S123',
            'sourceCreated' => false,
            'citationIds'   => [],
        ], $writeBack->toArray());

        $restored = WriteBack::fromArray($writeBack->toArray());

        self::assertNull($restored->buriFactId);
        self::assertSame('crem-9', $restored->cremFactId);
    }

    /**
     * fromArray rejects a missing required field.
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsAMissingRequiredField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray(['deatFactId' => 'd', 'sourceCreated' => true]); // no sourceXref
    }

    /**
     * fromArray rejects a present-but-non-bool sourceCreated, covering the `!is_bool($sourceCreated)`
     * arm of the combined guard (every other rejection row passes a valid boolean, so without this row
     * the bool branch is never the failing conjunct).
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsANonBoolSourceCreated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray([
            'deatFactId'    => 'd',
            'sourceXref'    => 'S1',
            'sourceCreated' => 1, // an int, not a bool
        ]);
    }

    /**
     * fromArray rejects a non-list<string> citationIds.
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsNonStringCitationIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray([
            'deatFactId'    => 'd',
            'sourceXref'    => 'S1',
            'sourceCreated' => true,
            'citationIds'   => [123],
        ]);
    }

    /**
     * fromArray rejects a present buriFactId that is not a string.
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsANonStringBuriFactId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray([
            'deatFactId'    => 'd',
            'sourceXref'    => 'S1',
            'sourceCreated' => true,
            'buriFactId'    => 123,
        ]);
    }

    /**
     * fromArray rejects a present cremFactId that is not a string.
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsANonStringCremFactId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray([
            'deatFactId'    => 'd',
            'sourceXref'    => 'S1',
            'sourceCreated' => true,
            'cremFactId'    => 123,
        ]);
    }

    /**
     * fromArray rejects an associative (non-list) citationIds.
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsANonListCitationIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteBack::fromArray([
            'deatFactId'    => 'd',
            'sourceXref'    => 'S1',
            'sourceCreated' => true,
            'citationIds'   => ['k' => 'v'],
        ]);
    }
}
