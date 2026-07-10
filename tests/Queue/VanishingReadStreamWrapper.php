<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

/**
 * A stream wrapper that reproduces the read-side TOCTOU race exactly: url_stat reports the path as a
 * readable, non-symlink REGULAR FILE — so the {@see \MagicSunday\ObituaryMatcher\Queue\AtomicFile::readJsonCapped}
 * preflight (`is_link` / `!is_file` / `!is_readable`) passes — but the subsequent stream_open FAILS, as
 * if the file had been removed or made unreadable (a concurrent clear/unlink) between the preflight and
 * the fopen. fopen on a wrapper whose stream_open returns false raises the "failed to open stream"
 * E_WARNING; under a webtrees-style handler that converts every E_WARNING into a thrown ErrorException,
 * that warning would be converted into a throw BEFORE the explicit `$handle === false` branch could run,
 * escaping the RuntimeException-only fail-soft guard — unless readJsonCapped swallows it with its own
 * scoped handler so fopen returns false and the false-branch raises a RuntimeException instead. This
 * wrapper drives exactly that path deterministically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class VanishingReadStreamWrapper
{
    /**
     * @var string The URL scheme this wrapper registers under.
     */
    public const string SCHEME = 'obituary-vanishing-read';

    /**
     * @var resource|null The PHP-provided stream context (unused, set by the engine).
     */
    public $context;

    /**
     * Registers the wrapper.
     *
     * @return void
     */
    public static function register(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    /**
     * Unregisters the wrapper.
     *
     * @return void
     */
    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * Fails every open, as a file removed between the preflight stat and the fopen would. Returning
     * false makes fopen raise the "failed to open stream" E_WARNING routed to the active error handler.
     *
     * @param string      $path       The stream path to open (unused beyond the scheme).
     * @param string      $mode       The requested fopen mode (unused).
     * @param int         $options    Stream-wrapper option flags (unused).
     * @param string|null $openedPath The resolved path, set by reference on success (never, here).
     *
     * @return bool Always false: the open loses, mirroring a vanished file.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /**
     * Reports the path as a readable, non-symlink regular file, so the readJsonCapped preflight passes
     * and control reaches the fopen the race then fails.
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
            'size'    => 2,
            'atime'   => 0,
            'mtime'   => 0,
            'ctime'   => 0,
            'blksize' => 0,
            'blocks'  => 0,
        ];
    }
}
