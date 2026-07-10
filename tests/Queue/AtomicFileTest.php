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
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function file_put_contents;
use function glob;
use function is_dir;
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
     * writeJson with a byte cap rejects an oversized payload BEFORE writing, so a file a capped reader
     * could never read back is never created on disk (a loud failure instead of a silent orphan).
     */
    #[Test]
    public function writeJsonRejectsAnOversizedPayloadBeforeWriting(): void
    {
        $path = $this->tmp . '/capped.json';

        try {
            AtomicFile::writeJson($path, ['x' => str_repeat('y', 200)], 50);
            self::fail('writeJson must reject a payload that exceeds the byte cap.');
        } catch (RuntimeException) {
            // Expected: the oversized payload is rejected.
        }

        self::assertFileDoesNotExist($path);
        self::assertSame([], glob($this->tmp . '/*'));
    }

    /**
     * writeJson with a byte cap accepts a payload within the cap and reads back identically, so the
     * cap rejects only genuinely oversized payloads.
     */
    #[Test]
    public function writeJsonAcceptsAPayloadWithinTheCap(): void
    {
        $path = $this->tmp . '/within.json';
        AtomicFile::writeJson($path, ['a' => 1], 1024);
        self::assertSame(['a' => 1], AtomicFile::readJsonCapped($path, 1024));
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
     * A file whose bytes are not valid JSON is rejected with a RuntimeException, NOT the
     * JSON_THROW_ON_ERROR JsonException (which extends \Exception, not RuntimeException). The reader's
     * callers isolate a poison row by catching RuntimeException, so leaking a JsonException here would
     * escape that guard and abort the whole directory scan instead of skipping the one corrupt entry.
     */
    #[Test]
    public function readJsonCappedConvertsBrokenJsonIntoARuntimeException(): void
    {
        $path = $this->tmp . '/broken.json';
        file_put_contents($path, '{not json');
        $this->expectException(RuntimeException::class);
        AtomicFile::readJsonCapped($path, 1024);
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
     * A partial write — fewer bytes than intended, as happens on a full filesystem or an exceeded
     * quota — must NOT be treated as success: file_put_contents reports the bytes actually written
     * rather than throwing, so a byte-count comparison against the intended length is the only thing
     * that catches the truncation. writeJson must reject it with a RuntimeException AND clean up the
     * truncated temp file, so a short write never renames a corrupt file into place nor leaks a
     * *.tmp.* file. A non-throwing error handler is installed so file_put_contents returns its short
     * count (the byte-count branch under test) instead of throwing on the write warning.
     */
    #[Test]
    public function writeJsonRejectsAndCleansUpAPartialWrite(): void
    {
        FailingWriteStreamWrapper::register();
        FailingWriteStreamWrapper::enableShortWrite();

        // Swallow the file_put_contents "bytes written" warning without throwing, so the write step
        // returns its short byte count and the byte-count comparison — not an error-handler throw —
        // is what rejects the partial write.
        set_error_handler(static fn (): bool => true);

        $message   = '';
        $leftovers = [];

        try {
            AtomicFile::writeJson(FailingWriteStreamWrapper::SCHEME . '://r.json', ['a' => 1]);
            self::fail('writeJson must reject a partial write.');
        } catch (AssertionFailedError $assertionFailure) {
            throw $assertionFailure;
        } catch (RuntimeException $runtimeException) {
            $message   = $runtimeException->getMessage();
            $glob      = glob(FailingWriteStreamWrapper::backingDirectory() . '/*.tmp.*');
            $leftovers = ($glob === false) ? [] : $glob;
        } finally {
            restore_error_handler();
            FailingWriteStreamWrapper::unregister();
        }

        // The rejection is the partial-write guard, and no truncated temp file is left behind.
        self::assertStringContainsStringIgnoringCase('completely', $message);
        self::assertSame([], $leftovers);
    }

    /**
     * ensureDirectory creates a missing directory (and any missing parents).
     */
    #[Test]
    public function ensureDirectoryCreatesAMissingDirectory(): void
    {
        $dir = $this->tmp . '/nested/leaf';

        AtomicFile::ensureDirectory($dir);

        self::assertDirectoryExists($dir);
    }

    /**
     * Calling ensureDirectory on an already-existing directory is a silent no-op: the is_dir probe
     * short-circuits before any mkdir, so a second call neither throws nor disturbs the directory.
     */
    #[Test]
    public function ensureDirectoryIsIdempotentOnAnExistingDirectory(): void
    {
        $dir = $this->tmp . '/existing';
        mkdir($dir, 0o700, true);

        AtomicFile::ensureDirectory($dir);

        self::assertDirectoryExists($dir);
    }

    /**
     * A genuine creation failure (the parent is a regular file, so no directory can be created under
     * it) surfaces as a RuntimeException rather than being swallowed silently.
     */
    #[Test]
    public function ensureDirectoryThrowsOnAGenuineFailure(): void
    {
        $parentFile = $this->tmp . '/not-a-directory';
        file_put_contents($parentFile, 'x');

        // The forced mkdir failure emits an expected warning; a scoped handler swallows it without the
        // forbidden @-suppression operator so the genuine-failure branch (not an error-handler throw)
        // is what raises.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to create directory/');
            AtomicFile::ensureDirectory($parentFile . '/child');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The key regression test for the directory-creation finding: under a custom error handler that
     * converts every E_WARNING into a thrown ErrorException (webtrees installs such a handler), a
     * BENIGN create race must NOT abort fatally. A stream wrapper reproduces the race deterministically:
     * the is_dir probe sees the directory as absent (so the create is attempted), then the internal
     * mkdir raises a "File exists" E_WARNING and loses — exactly as a concurrent process winning the
     * mkdir between the probe and the create would — and from then on the directory reports as present.
     *
     * The outer throwing handler would convert that mkdir warning into an ErrorException BEFORE the
     * "&& !is_dir()" recovery could run, aborting the benign race fatally — UNLESS ensureDirectory's
     * own scoped handler swallows it so mkdir returns false and the !is_dir() recovery observes the
     * now-present directory as success. ensureDirectory must therefore complete without throwing. With
     * the scoped handler removed this test fails (the converted ErrorException propagates).
     */
    #[Test]
    public function ensureDirectoryDoesNotThrowOnABenignRaceUnderAThrowingErrorHandler(): void
    {
        RacingMkdirStreamWrapper::register();

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        $raced = RacingMkdirStreamWrapper::SCHEME . '://raced';

        try {
            // The is_dir probe sees the path as absent, so the create is attempted; the wrapper's
            // mkdir then raises the "File exists" warning a lost race emits and reports the directory
            // as present. Without ensureDirectory's scoped handler the outer handler would convert
            // that warning into a thrown exception before the !is_dir() recovery could run.
            AtomicFile::ensureDirectory($raced);

            // Reaching here without an exception proves the scoped handler neutralised the converted
            // warning; the directory now reports as present, so the !is_dir() recovery counted the
            // lost race as success.
            self::assertTrue(is_dir($raced));
        } finally {
            restore_error_handler();
            RacingMkdirStreamWrapper::unregister();
        }
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

    /**
     * readJsonSection returns the array stored at the requested key of a valid document — the happy
     * path the per-person file stores read their own persisted state through.
     */
    #[Test]
    public function readJsonSectionReturnsTheRequestedSection(): void
    {
        $path = $this->tmp . '/section.json';
        AtomicFile::writeJson($path, ['coverage' => [['portal' => 'a']], 'other' => 1]);

        self::assertSame([['portal' => 'a']], AtomicFile::readJsonSection($path, 1024, 'coverage'));
    }

    /**
     * readJsonSection returns null for an absent file — the branch the "unrecorded person" and legacy
     * layouts route through (the new reader never opens the legacy path, so it sees no file).
     */
    #[Test]
    public function readJsonSectionReturnsNullForAnAbsentFile(): void
    {
        self::assertNull(AtomicFile::readJsonSection($this->tmp . '/missing.json', 1024, 'coverage'));
    }

    /**
     * readJsonSection returns null when the file is a valid JSON object but the requested key is
     * missing entirely — the "no section" branch. This is the exact shape a legacy single-document
     * (personId + a differently-named payload key) would present if it ever sat at the reader's path,
     * so the fail-soft contract must map it to null, not surface a partial read.
     */
    #[Test]
    public function readJsonSectionReturnsNullWhenTheKeyIsMissing(): void
    {
        $path = $this->tmp . '/no-key.json';
        AtomicFile::writeJson($path, ['personId' => 'I1', 'somethingElse' => []]);

        self::assertNull(AtomicFile::readJsonSection($path, 1024, 'coverage'));
    }

    /**
     * readJsonSection returns null when the requested key exists but its value is a scalar rather than
     * an array — the non-array-section branch. A corrupt document whose section was overwritten with a
     * scalar must read back as "no section" rather than being handed to a caller that expects a list.
     */
    #[Test]
    public function readJsonSectionReturnsNullWhenTheSectionIsNotAnArray(): void
    {
        $path = $this->tmp . '/scalar-section.json';
        AtomicFile::writeJson($path, ['coverage' => 'not-an-array']);

        self::assertNull(AtomicFile::readJsonSection($path, 1024, 'coverage'));
    }

    /**
     * A file that passes the preflight stat checks but cannot be OPENED — a read-side TOCTOU race where
     * the file is removed or made unreadable between the `is_file`/`is_readable` checks and the fopen —
     * is rejected with a RuntimeException even under a webtrees-style handler that converts the fopen
     * E_WARNING into a thrown exception. Without readJsonCapped's own scoped handler the converted
     * ErrorException (which does NOT extend RuntimeException) would be thrown FROM fopen(), bypassing
     * the "$handle === false" branch and escaping every caller's `catch (RuntimeException)` fail-soft
     * guard — crashing the tab render / drain path. A VanishingReadStreamWrapper drives that race
     * deterministically: url_stat reports a readable regular file, but stream_open fails.
     */
    #[Test]
    public function readJsonCappedRejectsAFileThatVanishesBetweenTheStatAndTheOpen(): void
    {
        VanishingReadStreamWrapper::register();

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->expectException(RuntimeException::class);
            AtomicFile::readJsonCapped(VanishingReadStreamWrapper::SCHEME . '://gone.json', 1024);
        } finally {
            restore_error_handler();
            VanishingReadStreamWrapper::unregister();
        }
    }

    /**
     * The fail-soft section read maps the same read-side open race to null rather than letting the
     * converted fopen warning escape: a coverage/memory document that vanishes between the preflight
     * stat and the open must degrade to "no section", so a concurrent clear/unlink never crashes the
     * render or drain path.
     */
    #[Test]
    public function readJsonSectionReturnsNullWhenTheFileVanishesBetweenTheStatAndTheOpen(): void
    {
        VanishingReadStreamWrapper::register();

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            self::assertNull(
                AtomicFile::readJsonSection(VanishingReadStreamWrapper::SCHEME . '://gone.json', 1024, 'coverage')
            );
        } finally {
            restore_error_handler();
            VanishingReadStreamWrapper::unregister();
        }
    }
}
