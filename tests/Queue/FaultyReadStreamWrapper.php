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
use function fread;
use function in_array;
use function is_resource;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A stream wrapper that reproduces a POST-OPEN read fault exactly: url_stat reports a readable regular
 * file and stream_open SUCCEEDS (so the {@see \MagicSunday\ObituaryMatcher\Queue\AtomicFile::readJsonCapped}
 * preflight and fopen both pass), but the first stream_read raises a GENUINE E_WARNING and returns false
 * — as an I/O error or a concurrent truncation on the resulting descriptor would. The warning is real,
 * not synthetic: stream_read performs an fread on a WRITE-only backing handle, so the engine emits the
 * same "Read ... failed: Bad file descriptor" warning a faulting read raises and routes it to whatever
 * error handler is active.
 *
 * Under a webtrees-style handler that converts every E_WARNING into a thrown ErrorException, that warning
 * would be thrown FROM the read BEFORE readJsonCapped's `$contents === false` recovery could run;
 * ErrorException does not extend RuntimeException, so it would escape every caller's fail-soft
 * catch (RuntimeException) — unless readJsonCapped's scoped handler spans the read (and the close) too.
 * This wrapper drives exactly that path deterministically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class FaultyReadStreamWrapper
{
    /**
     * @var string The URL scheme this wrapper registers under.
     */
    public const string SCHEME = 'obituary-faulty-read';

    /**
     * @var resource|null The PHP-provided stream context (unused, set by the engine).
     */
    public $context;

    /**
     * @var string|null The real backing file whose write-only handle makes the wrapper's read fault.
     */
    private static ?string $backing = null;

    /**
     * @var resource|null The write-only backing handle an fread faults on.
     */
    private $handle;

    /**
     * Registers the wrapper and creates the real backing file whose write-only handle provokes a
     * genuine read fault.
     *
     * @return void
     */
    public static function register(): void
    {
        self::$backing = tempnam(sys_get_temp_dir(), 'obituary-faulty-read');

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    /**
     * Unregisters the wrapper and removes the real backing file.
     *
     * @return void
     */
    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        if (self::$backing !== null) {
            unlink(self::$backing);
            self::$backing = null;
        }
    }

    /**
     * Opens the backing file WRITE-only and reports success, so the fopen in readJsonCapped succeeds and
     * control reaches the read the fread below then faults.
     *
     * @param string      $path       The stream path to open (unused beyond the scheme).
     * @param string      $mode       The requested fopen mode (unused).
     * @param int         $options    Stream-wrapper option flags (unused).
     * @param string|null $openedPath The resolved path, set by reference on success (unused).
     *
     * @return bool True: the open succeeds so the read fault is reached.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $handle = fopen((string) self::$backing, 'wb');

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Faults the read: an fread on the write-only backing handle raises the genuine "Bad file
     * descriptor" E_WARNING a faulting read emits, routed to the active error handler, and returns false.
     *
     * @param int $count The number of bytes requested (forwarded to the faulting fread).
     *
     * @return string|false Always false: the read faults, mirroring a post-open I/O error.
     */
    public function stream_read(int $count): string|false
    {
        if (
            ($count < 1)
            || !is_resource($this->handle)
        ) {
            return false;
        }

        return fread($this->handle, $count);
    }

    /**
     * Reports the stream as not yet at end-of-file, so stream_get_contents attempts the faulting read.
     *
     * @return bool Always false.
     */
    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * Closes the write-only backing handle.
     *
     * @return void
     */
    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Reports the path as a readable, non-symlink regular file, so the readJsonCapped preflight passes
     * and control reaches the fopen and the faulting read.
     *
     * @param string $path  The stream path to stat (unused beyond the scheme).
     * @param int    $flags Stat option flags (unused).
     *
     * @return array<int|string, int> A regular-file stat with broad read permission bits.
     */
    public function url_stat(string $path, int $flags): array
    {
        // S_IFREG (0100000) | 0666 in the st_mode slot makes is_file() true, is_link() false and
        // is_readable() true, so the preflight checks pass and the fopen below is reached.
        return [
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => 0o100666,
            'nlink'   => 1,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => 10,
            'atime'   => 0,
            'mtime'   => 0,
            'ctime'   => 0,
            'blksize' => 0,
            'blocks'  => 0,
        ];
    }
}
