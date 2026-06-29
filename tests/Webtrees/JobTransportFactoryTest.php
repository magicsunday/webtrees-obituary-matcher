<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Webtrees;

use GuzzleHttp\Client;
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Queue\FileJobTransport;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\RestJobTransport;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use MagicSunday\ObituaryMatcher\Webtrees\JobTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function sys_get_temp_dir;

/**
 * Verifies the transport selector: a file connection yields the file-drop transport, a REST connection
 * yields the REST transport, and a REST connection without an explicit ledger root is rejected (the
 * REST transport has no queue dir to fall back to, so a null root is a wiring error).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(JobTransportFactory::class)]
final class JobTransportFactoryTest extends TestCase
{
    /**
     * A file connection yields the file-drop transport; the rest-pending root is ignored.
     *
     * @return void
     */
    #[Test]
    public function aFileConnectionYieldsTheFileTransport(): void
    {
        $transport = JobTransportFactory::create(
            new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test'),
            FinderConnection::file(),
        );

        self::assertInstanceOf(FileJobTransport::class, $transport);
    }

    /**
     * A REST connection with an explicit ledger root yields the REST transport.
     *
     * @return void
     */
    #[Test]
    public function aRestConnectionYieldsTheRestTransport(): void
    {
        $transport = JobTransportFactory::create(
            new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test'),
            FinderConnection::rest('http://finder:8080', null),
            sys_get_temp_dir() . '/obituary-rest-pending-test',
        );

        self::assertInstanceOf(RestJobTransport::class, $transport);
    }

    /**
     * The REST transport's HTTP client is wired with bounded connect AND request timeouts, so a finder
     * that accepts the connection but stalls surfaces as a ClientExceptionInterface (which the transport
     * maps to a clean submission failure or a skip-and-retry) rather than blocking the enqueue web request
     * or the drain task forever. Pinned by reflecting the wired Guzzle client's config: the timeouts are a
     * composition-root concern with no behavioural seam to assert against (every transport test injects a
     * client double, so the real client's defaults are never otherwise exercised). The reflection is
     * deliberately coupled to the locked Guzzle 7 client shape to lock this production-critical invariant.
     *
     * @return void
     */
    #[Test]
    public function theRestTransportClientIsWiredWithBoundedTimeouts(): void
    {
        $transport = JobTransportFactory::create(
            new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test'),
            FinderConnection::rest('http://finder:8080', null),
            sys_get_temp_dir() . '/obituary-rest-pending-test',
        );

        $client = (new ReflectionProperty(RestJobTransport::class, 'http'))->getValue($transport);
        self::assertInstanceOf(Client::class, $client);

        $config = (new ReflectionProperty(Client::class, 'config'))->getValue($client);
        self::assertIsArray($config);

        // Both bounds must be non-zero: Guzzle treats 0 (its default for an unset option) as "wait
        // forever", which is the exact production hang this wiring prevents.
        self::assertGreaterThan(0, $config['connect_timeout'] ?? 0);
        self::assertGreaterThan(0, $config['timeout'] ?? 0);
    }

    /**
     * A REST connection without an explicit ledger root is rejected: the REST transport has no queue
     * directory to remember its in-flight jobs, so a null root is a wiring error.
     *
     * @return void
     */
    #[Test]
    public function aRestConnectionWithoutAPendingRootIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobTransportFactory::create(
            new QueuePaths(sys_get_temp_dir() . '/obituary-queue-test'),
            FinderConnection::rest('http://finder:8080', null),
            null,
        );
    }
}
