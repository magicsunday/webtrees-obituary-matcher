<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\FileJobTransport;
use MagicSunday\ObituaryMatcher\Queue\JobTransport;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Queue\ResponseReader;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Queue\RestJobTransport;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;

/**
 * Selects and wires the {@see JobTransport} a {@see FinderConnection} describes: the on-disk file-drop
 * queue ({@see FileJobTransport}) or the REST endpoint ({@see RestJobTransport}). It is the single place
 * the two transports are constructed, so the producer/drain composition roots stay oblivious to the
 * concrete transport and to the PSR-18/PSR-17 HTTP stack.
 *
 * Path-source discipline: the REST ledger root is passed in EXPLICITLY and is NEVER derived from the
 * queue {@see QueuePaths} here. The two roots have independent sources — the file queue root is the
 * caller's {@see QueuePaths}; the REST ledger root is resolved by the caller (CLI / control panel) from
 * {@see \MagicSunday\ObituaryMatcher\Support\WebtreesInstallLocator::defaultRestPendingRoot()} or a
 * CLI-derived sibling of the queue dir — so the ledger stays decoupled from the queue-dir layout.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class JobTransportFactory
{
    /**
     * @var int Seconds to wait for the TCP connection to the finder before treating it as unreachable.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @var int Seconds to wait for a finder response before treating the request as a transient fault.
     */
    private const int REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Builds the transport the connection selects. For the file transport the REST ledger root is
     * ignored; for the REST transport it is REQUIRED (a null root is a wiring error, because the REST
     * transport has no queue directory to fall back to and must remember its in-flight jobs somewhere).
     *
     * @param QueuePaths       $paths           The queue path builder rooted at the file queue root.
     * @param FinderConnection $connection      The connection selecting the transport (file or REST).
     * @param string|null      $restPendingRoot The REST in-flight ledger root, required for REST.
     *
     * @return JobTransport The wired transport.
     *
     * @throws InvalidArgumentException When the REST transport is selected without a ledger root.
     */
    public static function create(QueuePaths $paths, FinderConnection $connection, ?string $restPendingRoot = null): JobTransport
    {
        if ($connection->transport() === 'rest') {
            if ($restPendingRoot === null) {
                throw new InvalidArgumentException(
                    'The REST transport requires an explicit rest-pending ledger root.'
                );
            }

            $httpFactory = new HttpFactory();

            // Bound the connect and request waits. Without them Guzzle waits forever (its defaults are
            // 0 = infinite), so a finder that accepts the connection but stalls would hang the enqueue
            // web request or the drain task indefinitely. With the bounds a stalled finder surfaces as a
            // ClientExceptionInterface, which the transport already maps to a clean submission failure or
            // a skip-and-retry — degrading into the existing transient-fault handling instead of blocking.
            return new RestJobTransport(
                new Client([
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
                ]),
                $httpFactory,
                $httpFactory,
                new RestPendingLedger($restPendingRoot),
                $connection,
                new ResponseValidator(),
            );
        }

        return new FileJobTransport(
            new QueueClient($paths),
            new ResponseReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            $paths,
        );
    }
}
