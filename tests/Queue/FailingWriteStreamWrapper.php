<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use RuntimeException;

use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function stat;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A stream wrapper that creates a backing temporary file on open but FAILS the first write. It
 * reproduces a partial-write failure (a full filesystem, or a write E_WARNING the webtrees error
 * handler converts into a thrown exception) where the temporary file already exists on disk by the
 * time the write reports failure — the exact case {@see \MagicSunday\ObituaryMatcher\Queue\AtomicFile}
 * must clean up. Backed by a real per-registration directory so the test can assert that no leftover
 * temporary file remains, and so unlink/url_stat operate on the real file the helper under test
 * removes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class FailingWriteStreamWrapper
{
    /**
     * @var string The URL scheme this wrapper registers under.
     */
    public const string SCHEME = 'obituary-failing-write';

    /**
     * @var resource|null The PHP-provided stream context (unused, set by the engine).
     */
    public $context;

    /**
     * @var string|null The absolute backing directory for the current registration.
     */
    private static ?string $directory = null;

    /**
     * @var string The message thrown by {@see self::unlink()} when cleanup failure is armed. Chosen
     *             to be unmistakably distinct from any write/rename error so a test can prove which
     *             exception propagated.
     */
    public const string CLEANUP_FAILURE_MESSAGE = 'cleanup-unlink-sabotaged';

    /**
     * @var bool When true, {@see self::unlink()} throws {@see self::CLEANUP_FAILURE_MESSAGE} so the
     *           temp-file cleanup itself fails. This lets a test assert the ORIGINAL write failure
     *           still propagates rather than being masked by the cleanup error.
     */
    private static bool $failUnlink = false;

    /**
     * @var bool When true, {@see self::stream_write()} reports a SHORT write — one byte fewer than it
     *           was given — instead of a zero-byte write. This reproduces a partial write on a full
     *           filesystem so a test can prove that {@see \MagicSunday\ObituaryMatcher\Queue\AtomicFile}
     *           detects the truncated temp file via its byte-count comparison and never renames it into
     *           place.
     */
    private static bool $shortWrite = false;

    /**
     * @var resource|null The open backing file handle for this stream instance.
     */
    private $handle;

    /**
     * Registers the wrapper and creates a fresh, empty backing directory.
     *
     * @return void
     */
    public static function register(): void
    {
        self::$failUnlink = false;
        self::$shortWrite = false;
        self::$directory  = sys_get_temp_dir() . '/obituary-failwrite-' . uniqid('', true);
        mkdir(self::$directory, 0o700, true);

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    /**
     * Unregisters the wrapper and removes the backing directory and any files left in it.
     *
     * @return void
     */
    public static function unregister(): void
    {
        // Reset the unlink-failure flag first so the teardown's own unlink() calls below are not
        // sabotaged by a test that asked the wrapper's unlink to fail.
        self::$failUnlink = false;
        self::$shortWrite = false;

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        $directory = self::$directory;

        if (($directory !== null) && is_dir($directory)) {
            $entries = scandir($directory);

            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (
                        ($entry === '.')
                        || ($entry === '..')
                    ) {
                        continue;
                    }

                    unlink($directory . DIRECTORY_SEPARATOR . $entry);
                }
            }

            rmdir($directory);
        }

        self::$directory = null;
    }

    /**
     * Returns the absolute backing directory of the current registration.
     *
     * @return string The backing directory path.
     */
    public static function backingDirectory(): string
    {
        return (string) self::$directory;
    }

    /**
     * Arms the wrapper so its {@see self::unlink()} throws {@see self::CLEANUP_FAILURE_MESSAGE},
     * letting a test drive the temp-file cleanup itself into an exception.
     *
     * @return void
     */
    public static function failNextUnlink(): void
    {
        self::$failUnlink = true;
    }

    /**
     * Arms the wrapper so its {@see self::stream_write()} reports a SHORT write (one byte fewer than
     * requested) instead of a zero-byte write, reproducing a partial write on a full filesystem.
     *
     * @return void
     */
    public static function enableShortWrite(): void
    {
        self::$shortWrite = true;
    }

    /**
     * Creates the backing file on open so a leftover would exist if cleanup were skipped.
     *
     * @param string $path    The stream path (scheme://name).
     * @param string $mode    The open mode (ignored; always opened for writing).
     * @param int    $options Stream-wrapper option flags (unused).
     * @param string $opened  The opened path, by reference (unused).
     *
     * @return bool Always true: the backing file is created.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $handle = fopen($this->backingPath($path), 'wb');

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Fails the write AFTER the backing file already exists. In the default mode it reports a
     * zero-byte write so file_put_contents fails outright; in short-write mode it writes one byte
     * fewer than requested to the backing file and reports that short count, reproducing a partial
     * write on a full filesystem so the helper's byte-count comparison catches the truncation.
     *
     * @param string $data The bytes that would be written.
     *
     * @return int The number of bytes "written": 0 in the default mode, or one fewer than requested
     *             in short-write mode (zero for a single-byte chunk).
     */
    public function stream_write(string $data): int
    {
        if (!self::$shortWrite) {
            return 0;
        }

        $length = strlen($data);
        $short  = ($length > 0) ? ($length - 1) : 0;

        if (($short > 0) && ($this->handle !== null)) {
            fwrite($this->handle, substr($data, 0, $short));
        }

        return $short;
    }

    /**
     * Closes the backing file handle.
     *
     * @return void
     */
    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Removes the backing file when the helper under test unlinks the partial temporary file.
     *
     * @param string $path The stream path to unlink.
     *
     * @return bool True when the backing file was removed.
     *
     * @throws RuntimeException When cleanup failure is armed, simulating a cleanup unlink that throws.
     */
    public function unlink(string $path): bool
    {
        if (self::$failUnlink) {
            // Simulate a cleanup unlink that throws (e.g. a warning the webtrees error handler turns
            // into an exception). The helper under test must NOT let this mask the original write
            // failure: its best-effort catch swallows this and re-throws the original error.
            throw new RuntimeException(self::CLEANUP_FAILURE_MESSAGE);
        }

        return unlink($this->backingPath($path));
    }

    /**
     * Reports the backing file's stat so is_file() on the stream path reflects the real file.
     *
     * @param string $path  The stream path to stat.
     * @param int    $flags Stat option flags (unused).
     *
     * @return array<int|string, int>|false The backing file's stat, or false when absent.
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $backingPath = $this->backingPath($path);

        if (!is_file($backingPath)) {
            return false;
        }

        return stat($backingPath);
    }

    /**
     * Maps a stream path onto its real backing file path inside the registration directory.
     *
     * @param string $path The stream path (scheme://name).
     *
     * @return string The absolute backing file path.
     */
    private function backingPath(string $path): string
    {
        // Strip the "scheme://" prefix to recover the bare file name, then map it under the backing
        // directory. The names used in tests carry no nested directory separators.
        $name = substr($path, strlen(self::SCHEME . '://'));

        return self::backingDirectory() . DIRECTORY_SEPARATOR . $name;
    }
}
