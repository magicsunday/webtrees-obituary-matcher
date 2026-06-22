<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;

use function dirname;
use function file_get_contents;
use function is_dir;
use function json_decode;
use function mkdir;

use const JSON_THROW_ON_ERROR;

/**
 * Base test case for the Queue layer: extends the generic temporary-directory base with a helper
 * that places a feeder response into the queue's done directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class QueueTempDirTestCase extends TempDirTestCase
{
    /**
     * Writes the decoded fixture bytes into done/<jobId>/response.json, simulating the feeder's
     * final state after a successful scrape.
     *
     * @param string $jobId   The job identifier whose done directory receives the response.
     * @param string $fixture The fixture file name under tests/fixtures.
     *
     * @return void
     */
    protected function placeResponse(string $jobId, string $fixture): void
    {
        $path = (new QueuePaths($this->tmp))->doneDir($jobId) . '/response.json';

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/' . $fixture),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        AtomicFile::writeJson($path, $data);
    }
}
