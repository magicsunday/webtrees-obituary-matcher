<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Matching\IngestServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the ingest composition root assembles the full ingest object graph without error — a wiring
 * typo (wrong arg order/arity) throws at construction, so this pins the factory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(IngestServiceFactory::class)]
final class IngestServiceFactoryTest extends TestCase
{
    /**
     * The factory wires the full ingest graph without error. A wiring typo (wrong arg order/arity/type)
     * throws at construction, so a successful build with no exception is the contract this pins. There is
     * no return value to assert — the static return type already guarantees the concrete IngestService —
     * so the test deliberately performs no assertion beyond "construction succeeds".
     *
     * @return void
     */
    #[Test]
    public function createWiresTheIngestGraph(): void
    {
        $this->expectNotToPerformAssertions();

        IngestServiceFactory::create();
    }
}
