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
     * The factory wires the full ingest graph without error and returns a fresh instance per call (no
     * accidental memoised singleton). A wiring typo (wrong arg order/arity) throws at construction, so
     * a successful, distinct build pins the composition root.
     *
     * @return void
     */
    #[Test]
    public function createWiresTheIngestGraph(): void
    {
        $first  = IngestServiceFactory::create();
        $second = IngestServiceFactory::create();

        self::assertNotSame($first, $second);
    }
}
