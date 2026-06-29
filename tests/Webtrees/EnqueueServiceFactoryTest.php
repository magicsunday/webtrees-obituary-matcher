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
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
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
     * The factory wires the full producer graph over a queue root without error. A wiring typo (wrong
     * arg order/arity/type) throws at construction, so a successful build with no exception is the
     * contract this pins; the static return type already guarantees the concrete EnqueueService, so the
     * test deliberately performs no assertion beyond "construction succeeds".
     *
     * @return void
     */
    #[Test]
    public function createWiresTheProducerGraph(): void
    {
        $this->expectNotToPerformAssertions();

        $paths = new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test');

        EnqueueServiceFactory::create($paths);
    }

    /**
     * The factory accepts a REST connection and an explicit ledger root and assembles the producer graph
     * over the REST transport without error — pinning that the connection-driven REST branch wires
     * without throwing (the contract; no return value to assert beyond successful construction).
     *
     * @return void
     */
    #[Test]
    public function createWiresTheRestProducerGraph(): void
    {
        $this->expectNotToPerformAssertions();

        $paths      = new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test');
        $connection = FinderConnection::rest('http://finder:8080', null);
        $root       = sys_get_temp_dir() . '/obituary-rest-pending-test';

        EnqueueServiceFactory::create($paths, $connection, $root);
    }
}
