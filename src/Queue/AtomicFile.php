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

use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_file;
use function is_link;
use function is_readable;
use function json_decode;
use function json_encode;
use function rename;
use function sprintf;
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
     * Writes the given data as JSON to the target path tear-free. The payload is encoded, written to
     * a unique temporary file in the same directory and then renamed onto the target path so a reader
     * never observes a half-written file (the replacement is tear-free; it is not crash-durable, as
     * no fsync is issued). The caller guarantees the parent directory already exists.
     *
     * @param string               $path The absolute target path.
     * @param array<string, mixed> $data The data to encode and store.
     *
     * @return void
     *
     * @throws RuntimeException When encoding, the temporary write or the rename fails.
     */
    public static function writeJson(string $path, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $tmpPath = $path . '.tmp.' . uniqid('', true);

        if (file_put_contents($tmpPath, $json) === false) {
            throw new RuntimeException(
                sprintf('Failed to write temporary queue file: %s', $tmpPath)
            );
        }

        if (!rename($tmpPath, $path)) {
            // Remove the orphaned temp file so a failed rename does not leak a *.tmp.* file.
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }

            throw new RuntimeException(
                sprintf('Failed to atomically rename %s to %s', $tmpPath, $path)
            );
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
     * @throws RuntimeException When the path is a symlink, not a regular file, unreadable, its size
     *                          cannot be determined or it exceeds the byte cap.
     * @throws JsonException    When the file contents are not valid JSON (for example a truncated,
     *                          half-written row, as the atomic write is not crash-durable).
     */
    public static function readJsonCapped(string $path, int $maxBytes): array
    {
        if (
            is_link($path)
            || !is_file($path)
            || !is_readable($path)
        ) {
            throw new RuntimeException(
                sprintf('Refusing to read queue file: %s', $path)
            );
        }

        $size = filesize($path);

        if (
            ($size === false)
            || ($size > $maxBytes)
        ) {
            throw new RuntimeException(
                sprintf('Queue file size is invalid or exceeds the cap: %s', $path)
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                sprintf('Failed to read queue file: %s', $path)
            );
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
