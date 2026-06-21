<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the queue path builder and layout creation, including the jobId path-traversal guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(QueuePaths::class)]
final class QueuePathsTest extends TempDirTestCase
{
    #[Test]
    public function rejectsAJobIdWithPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueuePaths($this->tmp))->queuedDir('../escape');
    }

    #[Test]
    public function buildsStateDirsAndCreatesLayout(): void
    {
        $paths = new QueuePaths($this->tmp);
        $paths->ensureLayout();
        self::assertDirectoryExists($this->tmp . '/queued');
        self::assertSame($this->tmp . '/done/job-1', $paths->doneDir('job-1'));
    }
}
