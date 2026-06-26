<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Webtrees\EnqueueServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;

/**
 * Verifies the producer composition root assembles the full enqueue object graph without error — a
 * wiring typo (wrong arg order/arity) throws at construction, so this pins the factory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(EnqueueServiceFactory::class)]
final class EnqueueServiceFactoryTest extends TestCase
{
    /**
     * The factory wires the full producer graph over a queue root without error and returns a fresh
     * instance per call (no accidental memoised singleton). A wiring typo (wrong arg order/arity)
     * throws at construction, so a successful, distinct build pins the composition root.
     *
     * @return void
     */
    #[Test]
    public function createWiresTheProducerGraph(): void
    {
        $paths = new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test');

        $first  = EnqueueServiceFactory::create($paths);
        $second = EnqueueServiceFactory::create($paths);

        self::assertNotSame($first, $second);
    }
}
