<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function str_repeat;
use function symlink;

/**
 * Tests the atomic JSON write/read helper: round-trip, the size cap guard and the symlink guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(AtomicFile::class)]
final class AtomicFileTest extends TempDirTestCase
{
    #[Test]
    public function writeJsonThenReadJsonRoundTrips(): void
    {
        $path = $this->tmp . '/r.json';
        AtomicFile::writeJson($path, ['a' => 1]);
        self::assertSame(['a' => 1], AtomicFile::readJsonCapped($path, 1024));
    }

    #[Test]
    public function readJsonCappedRejectsAnOversizeFile(): void
    {
        $path = $this->tmp . '/big.json';
        AtomicFile::writeJson($path, ['x' => str_repeat('y', 200)]);
        $this->expectException(RuntimeException::class);
        AtomicFile::readJsonCapped($path, 50);
    }

    #[Test]
    public function readJsonCappedRejectsASymlink(): void
    {
        $real = $this->tmp . '/real.json';
        AtomicFile::writeJson($real, ['a' => 1]);
        $link = $this->tmp . '/link.json';
        symlink($real, $link);
        $this->expectException(RuntimeException::class);
        AtomicFile::readJsonCapped($link, 1024);
    }
}
