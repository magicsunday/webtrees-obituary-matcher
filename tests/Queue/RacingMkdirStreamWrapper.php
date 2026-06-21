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
use function is_dir;
use function mkdir;
use function rmdir;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function uniqid;

/**
 * A stream wrapper that reproduces the benign directory-creation RACE exactly: the first url_stat
 * reports the path as ABSENT (so the is_dir fast-path probe is false and the create is attempted),
 * but mkdir then raises a genuine "File exists" E_WARNING and fails — as a concurrent process winning
 * the mkdir between the probe and the create would — and from then on url_stat reports the path as an
 * existing directory so the !is_dir() recovery treats it as success.
 *
 * The "File exists" warning is real, not synthetic: the wrapper's mkdir attempts a recursive create
 * on a backing directory it has already created, so the engine emits the same warning a lost race
 * raises and routes it to whatever error handler is active. Under a webtrees-style handler that
 * converts every E_WARNING into a thrown ErrorException, that warning would be converted into a throw
 * BEFORE the recovery clause could run, aborting a benign race fatally — unless
 * {@see \MagicSunday\ObituaryMatcher\Queue\AtomicFile::ensureDirectory()} swallows it with its own
 * scoped handler. This wrapper drives exactly that path deterministically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RacingMkdirStreamWrapper
{
    /**
     * @var string The URL scheme this wrapper registers under.
     */
    public const string SCHEME = 'obituary-racing-mkdir';

    /**
     * @var resource|null The PHP-provided stream context (unused, set by the engine).
     */
    public $context;

    /**
     * @var bool Whether the raced directory is considered to exist yet. It starts absent so the
     *           is_dir probe is false and the create is attempted, then flips to present after the
     *           racing mkdir so the !is_dir() recovery succeeds.
     */
    private static bool $exists = false;

    /**
     * @var string|null The real backing directory whose pre-existence makes the wrapper's mkdir emit a
     *                  genuine "File exists" warning, mirroring the lost-race outcome.
     */
    private static ?string $backing = null;

    /**
     * Registers the wrapper, resets the raced directory to the absent (pre-race) state and creates the
     * real backing directory used to provoke a genuine "File exists" warning.
     *
     * @return void
     */
    public static function register(): void
    {
        self::$exists  = false;
        self::$backing = sys_get_temp_dir() . '/obituary-racing-mkdir-' . uniqid('', true);
        mkdir(self::$backing, 0o700, true);

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    /**
     * Unregisters the wrapper and removes the real backing directory.
     *
     * @return void
     */
    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        if (
            (self::$backing !== null)
            && is_dir(self::$backing)
        ) {
            rmdir(self::$backing);
        }

        self::$exists  = false;
        self::$backing = null;
    }

    /**
     * Loses the create with a GENUINE "File exists" warning (the racing outcome) and marks the
     * directory as present from now on, so the caller's !is_dir() recovery observes the directory and
     * succeeds. The warning is produced by a real recursive mkdir on the already-existing backing
     * directory, so the engine routes it to the active error handler exactly as a lost race would.
     *
     * @param string $path    The stream path to create (unused beyond the scheme).
     * @param int    $mode    The requested permission bits (unused).
     * @param int    $options Stream-wrapper option flags (unused).
     *
     * @return bool Always false: the racing mkdir loses, mirroring a concurrent winner.
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::$exists = true;

        // A recursive mkdir on the already-created backing directory raises the genuine
        // "mkdir(): File exists" E_WARNING that a lost race emits, routed to the active error handler.
        return mkdir((string) self::$backing, 0o700, true);
    }

    /**
     * Reports the raced directory as absent before the racing mkdir and as an existing directory
     * afterwards, driving the is_dir-then-mkdir-then-recovery sequence deterministically.
     *
     * @param string $path  The stream path to stat (unused beyond the scheme).
     * @param int    $flags Stat option flags (unused).
     *
     * @return array<int|string, int>|false A directory stat once present, or false while absent.
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!self::$exists) {
            return false;
        }

        // S_IFDIR (0040000) | 0700 in the st_mode slot makes is_dir() report a directory.
        return [
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => 0o040700,
            'nlink'   => 1,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => 0,
            'atime'   => 0,
            'mtime'   => 0,
            'ctime'   => 0,
            'blksize' => 0,
            'blocks'  => 0,
        ];
    }
}
