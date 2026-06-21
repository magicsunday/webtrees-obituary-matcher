<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Base test case for the Queue layer: provides a unique temporary working directory under the
 * system temp dir, created in setUp and removed recursively in tearDown so each test is isolated.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class TempDirTestCase extends TestCase
{
    /**
     * @var string Absolute path to the per-test temporary working directory.
     */
    protected string $tmp;

    /**
     * Creates a unique temporary working directory for the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/obituary-queue-' . uniqid('', true);
        mkdir($this->tmp, 0o700, true);
    }

    /**
     * Removes the temporary working directory and all of its contents recursively.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $this->removeRecursively($this->tmp);
        }

        parent::tearDown();
    }

    /**
     * Recursively deletes a directory and everything below it.
     *
     * @param string $directory Absolute path to the directory to delete.
     *
     * @return void
     */
    private function removeRecursively(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
