<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use function is_dir;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Shared plumbing for the store-coupled integration harnesses ({@see AbstractDrainTestCase} and
 * {@see AbstractEnqueueTestCase}): an isolated per-tree match-store root, created in {@see setUp()} and
 * recursively removed in {@see tearDown()}, so the assertions never touch the live webtrees data dir.
 * The transport is a {@see RecordingJobTransport} double, so the harness no longer lays out an on-disk
 * queue. The temp-dir leaf prefix is the one knob each concrete harness varies so its scenarios get a
 * distinct, identifiable working directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractStoreTestCase extends IntegrationTestCase
{
    /**
     * @var string The throwaway per-tree match-store base directory, isolated from the live data dir.
     */
    protected string $storeRoot;

    /**
     * Create the throwaway store root.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storeRoot = sys_get_temp_dir() . '/' . $this->tempDirPrefix() . uniqid('', true);

        mkdir($this->storeRoot, 0o700, true);
    }

    /**
     * Remove the throwaway store root.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeRecursively($this->storeRoot);

        parent::tearDown();
    }

    /**
     * The leaf prefix for this harness's throwaway temp directory (e.g. "obituary-drain-"), so each
     * concrete harness's scenarios get a distinct, identifiable working directory.
     *
     * @return string The temp-dir leaf prefix.
     */
    abstract protected function tempDirPrefix(): string;

    /**
     * Recursively remove a directory tree.
     *
     * @param string $directory The directory to remove.
     *
     * @return void
     */
    protected function removeRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            if (
                ($entry === '.')
                || ($entry === '..')
            ) {
                continue;
            }

            $path = $directory . '/' . $entry;

            // A symlink to a directory reports is_dir() === true; recursing into it would delete the
            // LINK TARGET's contents outside the temp dir. Unlink the link itself instead of traversing.
            if (
                is_dir($path)
                && !is_link($path)
            ) {
                $this->removeRecursively($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
