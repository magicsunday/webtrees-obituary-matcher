<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use MagicSunday\ObituaryMatcher\Test\Support\RemovesFlatTempStoreTrait;

use function mkdir;

/**
 * Shared plumbing for the store-coupled integration harnesses ({@see AbstractDrainTestCase} and
 * {@see AbstractEnqueueTestCase}): an isolated per-tree match-store root, created in {@see setUp()} and
 * recursively removed in {@see tearDown()}, so the assertions never touch the live webtrees data dir.
 * The recursive temp-store teardown is shared with the other suites through
 * {@see RemovesFlatTempStoreTrait} (this harness extends {@see IntegrationTestCase}, so the shared
 * behaviour is a trait rather than a common base). The transport is a {@see RecordingJobTransport}
 * double, so the harness no longer lays out an on-disk queue. The temp-dir leaf prefix is the one knob
 * each concrete harness varies so its scenarios get a distinct, identifiable working directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class AbstractStoreTestCase extends IntegrationTestCase
{
    use RemovesFlatTempStoreTrait;

    /**
     * @var string The throwaway per-tree match-store base directory, isolated from the live data dir.
     */
    protected string $storeRoot;

    /**
     * Create the throwaway store root. The trait records the path for the recursive teardown; it does not
     * create the directory, so it is made here.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storeRoot = $this->makeFlatStoreDir($this->tempDirPrefix());

        mkdir($this->storeRoot, 0o700, true);
    }

    /**
     * Remove the throwaway store root and everything below it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeFlatStoreDir();

        parent::tearDown();
    }

    /**
     * The leaf prefix for this harness's throwaway temp directory (e.g. "obituary-drain-"), so each
     * concrete harness's scenarios get a distinct, identifiable working directory.
     *
     * @return string The temp-dir leaf prefix.
     */
    abstract protected function tempDirPrefix(): string;
}
