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
     * The write itself can fail AFTER the temporary file has already been created on disk — a real
     * partial write (a full filesystem, or a warning the webtrees error handler converts into a thrown
     * exception). A stream wrapper reproduces exactly that: stream_open creates the backing temp file,
     * but the first stream_write fails, so file_put_contents reports failure with the temp file
     * already present. writeJson must then propagate the failure AND clean up the partial temp file,
     * so a failed write never leaks a *.tmp.* file into the queue directory. Under the old
     * write-outside-the-try order the partial temp file would leak.
     */
    #[Test]
    public function writeJsonCleansUpTheTempFileWhenTheWriteItselfFailsAfterCreation(): void
    {
        FailingWriteStreamWrapper::register();

        // The webtrees error handler converts the partial-write E_WARNING into a thrown exception,
        // which leaves file_put_contents through a throw rather than a false return — so the cleanup
        // must be reachable from inside the write step, not only from the rename branch.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        $leftovers = [];

        try {
            AtomicFile::writeJson(FailingWriteStreamWrapper::SCHEME . '://r.json', ['a' => 1]);
            self::fail('writeJson must propagate the partial-write failure.');
        } catch (AssertionFailedError $assertionFailure) {
            throw $assertionFailure;
        } catch (Throwable) {
            // The partial-write failure propagated as expected. Capture any leftover BEFORE the
            // wrapper teardown removes the backing directory.
            $glob      = glob(FailingWriteStreamWrapper::backingDirectory() . '/*.tmp.*');
            $leftovers = ($glob === false) ? [] : $glob;
        } finally {
            restore_error_handler();
            FailingWriteStreamWrapper::unregister();
        }

        // No leftover *.tmp.* file: the cleanup ran even though the temp file had already been created
        // on disk before the write failed.
        self::assertSame([], $leftovers);
    }

    /**
     * When the write fails AND the temp-file cleanup ALSO fails, the ORIGINAL write failure must be
     * the exception that propagates — a cleanup failure must never mask the error the caller needs to
     * see. The wrapper fails the first write (so file_put_contents throws via the error handler) and
     * is armed so its cleanup unlink throws a recognisable exception too. With best-effort cleanup the
     * original write failure propagates and the cleanup exception is swallowed; without it the cleanup
     * exception would mask the original error the caller needs.
     */
    #[Test]
    public function writeJsonPropagatesTheOriginalWriteFailureWhenCleanupAlsoFails(): void
    {
        FailingWriteStreamWrapper::register();
        FailingWriteStreamWrapper::failNextUnlink();

        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        // The self::fail below proves an exception DID propagate (cleanup did not swallow it); this
        // captures WHICH one so the assertions below can prove it was the original, not the cleanup.
        $propagatedMessage = '';

        try {
            AtomicFile::writeJson(FailingWriteStreamWrapper::SCHEME . '://r.json', ['a' => 1]);
            self::fail('writeJson must propagate the original write failure.');
        } catch (AssertionFailedError $assertionFailure) {
            throw $assertionFailure;
        } catch (Throwable $throwable) {
            $propagatedMessage = $throwable->getMessage();
        } finally {
            restore_error_handler();
            FailingWriteStreamWrapper::unregister();
        }

        // The propagated exception is the ORIGINAL write failure, NOT the cleanup unlink exception
        // that the best-effort catch swallowed.
        self::assertStringNotContainsString(
            FailingWriteStreamWrapper::CLEANUP_FAILURE_MESSAGE,
            $propagatedMessage
        );
        self::assertStringContainsStringIgnoringCase('written', $propagatedMessage);
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
