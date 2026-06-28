<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use JsonException;
use RuntimeException;
use Throwable;

use function fclose;
use function file_put_contents;
use function fopen;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stream_get_contents;
use function strlen;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Static-only helper for tear-free JSON file I/O on the file-drop queue. Writes go through a
 * uniquely-named temporary file in the same directory followed by an atomic rename, so a reader
 * never observes a half-written file: the replacement is tear-free (the temp name is excluded from
 * the *.json scans). It is not crash-durable — there is no fsync, so durability is deferred to a
 * later phase. Reads are guarded against symlinks and oversized files so a hostile or corrupt queue
 * entry cannot exhaust memory or escape the queue via a link.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class AtomicFile
{
    /**
     * Constructor. Private to enforce static-only use.
     */
    private function __construct()
    {
    }

    /**
     * Creates the given directory (and any missing parents) if it does not yet exist. The create is
     * race-safe even under a custom error handler: a concurrent process winning the mkdir between the
     * is_dir probe and the create makes mkdir raise a "File exists" E_WARNING, which webtrees' error
     * handler would otherwise convert into a thrown ErrorException BEFORE the "&& !is_dir()" recovery
     * clause can run — aborting a benign race fatally. A scoped error handler swallows that warning so
     * mkdir RETURNS false and the !is_dir() recovery treats the now-present directory as success; only
     * a genuine failure (the directory still does not exist afterwards) raises.
     *
     * @param string $dir The absolute path of the directory to create.
     *
     * @return void
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        // Swallow the mkdir warning (a concurrent winner raises "File exists") so mkdir returns false
        // and the !is_dir() recovery below runs, rather than the warning being converted into a thrown
        // exception that bypasses the recovery. This mirrors the scoped-handler guard QueueClient::claim
        // already uses for its rename, without the forbidden @-suppression operator.
        set_error_handler(static fn (): bool => true);

        try {
            $created = mkdir($dir, 0o700, true);
        } finally {
            restore_error_handler();
        }

        if (
            !$created
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                sprintf('Failed to create directory: %s', $dir)
            );
        }
    }

    /**
     * Writes the given data as JSON to the target path tear-free. The payload is encoded, written to
     * a unique temporary file in the same directory and then renamed onto the target path so a reader
     * never observes a half-written file (the replacement is tear-free; it is not crash-durable, as
     * no fsync is issued). The caller guarantees the parent directory already exists.
     *
     * @param string               $path     The absolute target path.
     * @param array<string, mixed> $data     The data to encode and store.
     * @param int|null             $maxBytes The maximum accepted encoded size in bytes, or null for no
     *                                       cap. When set, an oversized payload is rejected BEFORE the
     *                                       write, so a file that a capped reader could never read back
     *                                       is never written (a loud failure instead of a silent orphan).
     *
     * @return void
     *
     * @throws RuntimeException When encoding fails, the encoded payload exceeds $maxBytes, or the
     *                          temporary write or the rename fails.
     */
    public static function writeJson(string $path, array $data, ?int $maxBytes = null): void
    {
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (
            ($maxBytes !== null)
            && (strlen($json) > $maxBytes)
        ) {
            throw new RuntimeException(
                sprintf('Refusing to write a queue file that exceeds the %d-byte cap: %s', $maxBytes, $path)
            );
        }

        $tmpPath = $path . '.tmp.' . uniqid('', true);

        // Wrap BOTH the write and the rename so the temp file is always cleaned up on failure. A
        // custom error handler (webtrees installs one) can convert a file_put_contents or rename
        // E_WARNING into a thrown exception FROM the call itself, bypassing the explicit failure
        // branches and leaking the *.tmp.* file once it has been created on disk (a partial write on
        // a full filesystem creates the temp file, then fails); catching every Throwable and removing
        // the temp file in the catch closes both leaks.
        try {
            // file_put_contents returns the NUMBER OF BYTES written, not a boolean: on a full disk or
            // an exceeded quota it can write FEWER bytes than intended (a partial write) WITHOUT
            // returning false. Comparing the byte count against the intended length catches BOTH the
            // false return AND a short write, so a truncated temp file is never renamed into place.
            // strlen() is the byte length of the JSON string, which is the correct unit here.
            $bytesWritten = file_put_contents($tmpPath, $json);

            if ($bytesWritten !== strlen($json)) {
                throw new RuntimeException(
                    sprintf('Failed to write queue file completely: %s', $path)
                );
            }

            if (!rename($tmpPath, $path)) {
                throw new RuntimeException(
                    sprintf('Failed to atomically rename %s to %s', $tmpPath, $path)
                );
            }
        } catch (Throwable $exception) {
            // Remove the orphaned temp file so a failed write or rename does not leak a *.tmp.* file.
            // The cleanup is best-effort: an unlink that itself throws (a warning the webtrees error
            // handler converts into an exception) must never mask the original failure, so it is
            // swallowed and the original $exception is always the one re-thrown.
            try {
                if (is_file($tmpPath)) {
                    unlink($tmpPath);
                }
            } catch (Throwable) {
                // Best-effort cleanup: never let a cleanup failure mask the original error.
            }

            throw $exception;
        }
    }

    /**
     * Reads and decodes a JSON file, rejecting symlinks, non-regular files, unreadable files and
     * files larger than the given byte cap. The result is an associative array; the caller is
     * responsible for narrowing it to a concrete shape.
     *
     * @param string $path     The absolute path to read.
     * @param int    $maxBytes The maximum accepted file size in bytes.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the path is a symlink, not a regular file, unreadable, cannot be
     *                          opened or read, exceeds the byte cap, or its contents are not valid
     *                          JSON (for example a truncated, half-written row, as the atomic write is
     *                          not crash-durable) or do not decode to a JSON object.
     */
    public static function readJsonCapped(string $path, int $maxBytes): array
    {
        // Reject a symlink before any open so a hostile link is never followed.
        if (
            is_link($path)
            || !is_file($path)
            || !is_readable($path)
        ) {
            throw new RuntimeException(
                sprintf('Refusing to read queue file: %s', $path)
            );
        }

        // Read through a stream capped at one byte beyond the limit, rather than stat-then-read: a
        // filesize() value comes from the stat cache (it can be stale) and leaves a size TOCTOU
        // between the stat and the read. Reading $maxBytes + 1 bytes lets the cap be enforced on the
        // bytes actually read.
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                sprintf('Failed to open queue file: %s', $path)
            );
        }

        try {
            $contents = stream_get_contents($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            throw new RuntimeException(
                sprintf('Failed to read queue file: %s', $path)
            );
        }

        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException(
                sprintf('Queue file exceeds the size cap: %s', $path)
            );
        }

        // Convert the JSON_THROW_ON_ERROR JsonException into a RuntimeException so a decode failure
        // (a truncated, half-written or hand-corrupted row) is isolated by the same poison-row guard
        // every caller already uses. JsonException extends \Exception, NOT RuntimeException, so a
        // caller catching only RuntimeException for isolation would otherwise miss it and a directory
        // scan would abort on one corrupt entry — the same footgun the non-array case below converts.
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Queue file is not valid JSON: %s', $path),
                0,
                $exception
            );
        }

        // A top-level non-array JSON document (for example a bare "null", "42" or "\"x\"") decodes to
        // a scalar/null. Returning that from this ": array" method would throw a TypeError, which is
        // not a RuntimeException — so a caller's catch (RuntimeException) would miss it and a directory
        // scan would crash. Convert it into a RuntimeException so the poison-row isolation can catch
        // it, mirroring the broken-JSON conversion above.
        if (!is_array($data)) {
            throw new RuntimeException(
                sprintf('Queue file does not contain a JSON object: %s', $path)
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
