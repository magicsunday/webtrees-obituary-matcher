<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use MagicSunday\ObituaryMatcher\Queue\CompletedJob;
use MagicSunday\ObituaryMatcher\Queue\FailedJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two transport outcome value objects: a completed job carrying its validated per-person
 * notices, and a failed job carrying its snake_case reason category. Both are plain immutable
 * carriers, so the assertions pin that each constructor argument lands on its public property.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(CompletedJob::class)]
#[CoversClass(FailedJob::class)]
final class CompletedJobTest extends TestCase
{
    #[Test]
    public function aCompletedJobCarriesItsValidatedNotices(): void
    {
        $job = new CompletedJob('job-1', 7, ['I1'], ['I1' => []]);

        self::assertSame('job-1', $job->jobId);
        self::assertSame(7, $job->treeId);
        self::assertSame(['I1'], $job->requestedPersonIds);
        self::assertSame(['I1' => []], $job->notices);
    }

    #[Test]
    public function aFailedJobCarriesItsReasonCategory(): void
    {
        $job = new FailedJob('job-2', 7, ['I2'], 'finder_failed');

        self::assertSame('job-2', $job->jobId);
        self::assertSame(7, $job->treeId);
        self::assertSame(['I2'], $job->requestedPersonIds);
        self::assertSame('finder_failed', $job->reasonCategory);
    }
}
