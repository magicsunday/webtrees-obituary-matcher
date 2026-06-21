<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use function fclose;
use function fopen;
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
        self::$directory = sys_get_temp_dir() . '/obituary-failwrite-' . uniqid('', true);
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
     * Fails the write so file_put_contents reports failure AFTER the backing file already exists.
     *
     * @param string $data The bytes that would be written (discarded).
     *
     * @return int Always 0: zero bytes written signals a short/failed write to file_put_contents.
     */
    public function stream_write(string $data): int
    {
        return 0;
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
     */
    public function unlink(string $path): bool
    {
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
