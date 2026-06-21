<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use ErrorException;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function file_put_contents;
use function glob;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
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
    /**
     * Data written with writeJson reads back identically through readJsonCapped.
     */
    #[Test]
    public function writeJsonThenReadJsonRoundTrips(): void
    {
        $path = $this->tmp . '/r.json';
        AtomicFile::writeJson($path, ['a' => 1]);
        self::assertSame(['a' => 1], AtomicFile::readJsonCapped($path, 1024));
    }

    /**
     * A file larger than the supplied byte cap is rejected rather than read into memory.
     */
    #[Test]
    public function readJsonCappedRejectsAnOversizeFile(): void
    {
        $path = $this->tmp . '/big.json';
        AtomicFile::writeJson($path, ['x' => str_repeat('y', 200)]);
        $this->expectException(RuntimeException::class);
        AtomicFile::readJsonCapped($path, 50);
    }

    /**
     * A symlinked path is refused so a hostile queue entry cannot escape the queue via a link.
     */
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

    /**
     * Under a custom error handler that converts the rename E_WARNING into an exception (webtrees
     * installs such a handler), a failing atomic rename throws FROM rename() rather than returning
     * false. The thrown exception must still propagate AND the temporary file must be cleaned up, so
     * a failed write never leaks a *.tmp.* file into the queue directory.
     */
    #[Test]
    public function writeJsonCleansUpTheTempFileWhenAnErrorHandlerThrowsOnRename(): void
    {
        // Point the target at an existing, non-empty directory: rename() onto a non-empty directory
        // fails, and the installed error handler turns that warning into an ErrorException.
        $target = $this->tmp . '/target-dir';
        mkdir($target, 0o700, true);
        file_put_contents($target . '/occupant', 'x');

        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            AtomicFile::writeJson($target, ['a' => 1]);
            self::fail('writeJson must propagate the rename failure.');
        } catch (AssertionFailedError $assertionFailure) {
            // The self::fail above means no exception propagated: re-throw so the test fails loudly.
            throw $assertionFailure;
        } catch (Throwable) {
            // The rename failure propagated as expected.
        } finally {
            restore_error_handler();
        }

        // No leftover *.tmp.* file: the cleanup ran even though the exception bypassed the
        // "if (!rename(...))" branch.
        $leftovers = glob($this->tmp . '/*.tmp.*');
        self::assertSame([], ($leftovers === false) ? [] : $leftovers);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function topLevelNonArrayPayloads(): array
    {
        return [
            'top-level null'    => ['null'],
            'top-level integer' => ['42'],
            'top-level string'  => ['"x"'],
        ];
    }

    /**
     * A valid-JSON file whose top-level document is a scalar/null (not an object/array) is rejected
     * with a RuntimeException, NOT an uncaught TypeError from the ": array" return type. The reader's
     * callers only catch JsonException|RuntimeException, so a leaked TypeError would crash the whole
     * directory scan instead of isolating the one poison row.
     */
    #[Test]
    #[DataProvider('topLevelNonArrayPayloads')]
    public function readJsonCappedRejectsATopLevelNonArrayPayload(string $payload): void
    {
        $path = $this->tmp . '/scalar.json';
        file_put_contents($path, $payload);
        $this->expectException(RuntimeException::class);
        AtomicFile::readJsonCapped($path, 1024);
    }
}
