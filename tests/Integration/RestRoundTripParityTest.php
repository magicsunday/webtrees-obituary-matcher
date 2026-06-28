<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Queue\ResponseValidator;
use MagicSunday\ObituaryMatcher\Queue\RestJobTransport;
use MagicSunday\ObituaryMatcher\Queue\RestPendingLedger;
use MagicSunday\ObituaryMatcher\Support\FinderConnection;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function json_encode;
use function mkdir;

use const JSON_THROW_ON_ERROR;

/**
 * Proves the transport seam is result-equivalent: the SAME candidate and the SAME finder notice, drained
 * once through the file-drop transport and once through the REST transport, persist byte-identical
 * suggestions. Both transports hand the drain a {@see \MagicSunday\ObituaryMatcher\Queue\CompletedJob}
 * built by the SAME {@see ResponseValidator}, so the ingest — and therefore the stored match — cannot
 * diverge across the seam. This is the headline guarantee of the REST transport work (#57).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class RestRoundTripParityTest extends AbstractDrainTestCase
{
    /**
     * The file and REST transports persist the SAME suggestion for the same candidate and notice. The
     * file path drains a seeded done job; the REST path drains a ledger-recorded job whose stub finder
     * returns the identical notice. Each drains into its own store, and the two stores' pending
     * suggestions are asserted equal.
     *
     * @return void
     */
    #[Test]
    public function restAndFileTransportsPersistTheSameSuggestionsForTheSameCandidates(): void
    {
        $tree   = $this->ottoTree('Otto Parity');
        $notice = $this->notice('Otto Searchable', 'https://example.test/parity');

        // File path: seed a done job whose response carries the shared notice, drain via the file
        // transport into store A.
        $storeA = new FileMatchStore($this->storeRoot . '/A');
        $jobDir = $this->paths()->doneDir('job-file');

        mkdir($jobDir, 0o700, true);
        AtomicFile::writeJson(
            $jobDir . '/request.json',
            ['schemaVersion' => 3, 'jobId' => 'job-file', 'treeId' => $tree->id(), 'candidates' => [['personId' => 'I1']]],
        );
        AtomicFile::writeJson(
            $jobDir . '/response.json',
            ['schemaVersion' => 1, 'jobId' => 'job-file', 'results' => ['I1' => [$notice]]],
        );

        $this->drainService($storeA)->drain(null, 20);

        // REST path: record the same candidate in a ledger, stub the finder to return the SAME notice,
        // drain via the REST transport into store B.
        $storeB = new FileMatchStore($this->storeRoot . '/B');
        $ledger = new RestPendingLedger($this->storeRoot . '/rest-pending');
        $ledger->record('job-rest', $tree->id(), ['I1'], '2026-06-23T10:00:00Z');

        $doneBody = ['schemaVersion' => 1, 'jobId' => 'job-rest', 'state' => 'done', 'results' => ['I1' => [$notice]]];
        $factory  = new HttpFactory();

        $restTransport = new RestJobTransport(
            $this->stubFinder($doneBody),
            $factory,
            $factory,
            $ledger,
            FinderConnection::rest('https://finder.example', null),
            new ResponseValidator(),
        );

        $this->drainService($storeB, $restTransport)->drain(null, 20);

        // Parity: the two transports persisted the identical pending suggestion (same personId, url,
        // status and scoring payload). The queue jobId is not part of a stored match, so job-file vs
        // job-rest does not enter the comparison.
        $fileSuggestions = $storeA->allPending();
        $restSuggestions = $storeB->allPending();

        self::assertCount(1, $fileSuggestions);
        self::assertCount(1, $restSuggestions);
        self::assertEquals($fileSuggestions, $restSuggestions);
    }

    /**
     * Builds a stub PSR-18 client that answers every GET with the given done body and every other method
     * (the best-effort DELETE) with an empty 200, so the REST drain polls the job to completion.
     *
     * @param array<string, mixed> $doneBody The #56 done job-response the finder returns for the poll.
     *
     * @return ClientInterface The stub finder client.
     */
    private function stubFinder(array $doneBody): ClientInterface
    {
        return new class($doneBody) implements ClientInterface {
            /**
             * @param array<string, mixed> $doneBody The done job-response returned for the poll.
             */
            public function __construct(private array $doneBody)
            {
            }

            /**
             * {@inheritDoc}
             */
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($request->getMethod() === 'GET') {
                    return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->doneBody, JSON_THROW_ON_ERROR));
                }

                return new Response(200, [], '');
            }
        };
    }
}
