<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

/**
 * Shared lifecycle for tests that drive a flat, single-directory file store ({@see \MagicSunday\ObituaryMatcher\Matching\FileMatchStore}).
 * It hands each test a unique temp directory and removes that directory and its (non-recursive) JSON
 * rows on teardown, so a failing assertion never leaks a directory under the system temp path. It is
 * a trait rather than a base class because its consumers must extend an unrelated base (the
 * webtrees-booting integration case) and PHP allows only single inheritance. The recursive
 * {@see TempDirTestCase} stays the base-class choice for the queue tests' nested layouts.
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
     * Removes the recorded temp store directory and its flat row files, tolerating a directory that
     * was never created.
     *
     * @return void
     */
    protected function removeFlatStoreDir(): void
    {
        if (($this->flatStoreDir === '') || !is_dir($this->flatStoreDir)) {
            return;
        }

        $files = glob($this->flatStoreDir . '/*');

        if ($files !== false) {
            array_map('unlink', $files);
        }

        rmdir($this->flatStoreDir);
    }
}
