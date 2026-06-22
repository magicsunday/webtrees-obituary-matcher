<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Shared lifecycle for tests that drive a file store ({@see \MagicSunday\ObituaryMatcher\Matching\FileMatchStore}).
 * It hands each test a unique temp directory and removes that directory and everything below it
 * (recursively — the store groups rows under per-candidate sub-directories) on teardown, so a failing
 * assertion never leaks a directory under the system temp path. It is a trait rather than a base class
 * because its consumers must extend an unrelated base (the webtrees-booting integration case) and PHP
 * allows only single inheritance. The {@see TempDirTestCase} stays the base-class choice for tests that
 * want the temp directory created up front.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
trait RemovesFlatTempStoreTrait
{
    /**
     * The temporary store directory created per test; the empty default makes the teardown a no-op
     * for a test that never allocated one.
     *
     * @var string
     */
    private string $flatStoreDir = '';

    /**
     * Returns a unique, not-yet-created temp store directory path under the system temp directory,
     * recording it for teardown.
     *
     * @param string $prefix The directory-name prefix that identifies the consuming suite.
     *
     * @return string The unique temp store directory path.
     */
    protected function makeFlatStoreDir(string $prefix): string
    {
        $this->flatStoreDir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));

        return $this->flatStoreDir;
    }

    /**
     * Removes the recorded temp store directory and everything below it recursively, tolerating a
     * directory that was never created. The store groups rows under per-candidate sub-directories, so a
     * non-recursive unlink would leave the sub-directories behind and fail the final rmdir.
     *
     * @return void
     */
    protected function removeFlatStoreDir(): void
    {
        if (($this->flatStoreDir === '') || !is_dir($this->flatStoreDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->flatStoreDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if (
                $entry->isDir()
                && !$entry->isLink()
            ) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->flatStoreDir);
    }
}
