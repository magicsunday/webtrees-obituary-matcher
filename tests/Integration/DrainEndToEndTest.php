<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use MagicSunday\ObituaryMatcher\Queue\JobState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The single end-to-end proof of the Phase-2e drain: a fixture done job (request schema v2 +
 * response schema v1, an `.example` cemetery and a funeral date) over a REAL imported tree is driven
 * through the SAME full dependency graph the {@see \tools/drain.php} CLI composition root assembles
 * (via {@see AbstractDrainTestCase::drainService()}) — not a partial or mocked wiring — and the run
 * is asserted to land the harvested burial facts in the per-tree store, finalise the job under
 * `ingested/`, and be a no-op on re-run.
 *
 * Where {@see DrainServiceTest} exhaustively pins the individual drain BRANCHES (tree-filter release,
 * privacy/unknown-xref skips, schema-invalid parking, stale counting), this test pins the whole
 * vertical once: it is the regression anchor that the real composition root keeps producing a stored
 * row carrying BOTH the cemetery and the funeral date the harvester extracts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversNothing]
final class DrainEndToEndTest extends AbstractDrainTestCase
{
    /**
     * The full drain composition root drives a fixture done job all the way into the per-tree store:
     * one job ingested with a stored row, the job finalised to `ingested/`, the persisted row carrying
     * BOTH harvested burial facts (cemetery + funeral date), and a re-run that adds nothing.
     */
    #[Test]
    public function aFixtureJobDrainsIntoAnIngestedStoreRowCarryingTheBurialFacts(): void
    {
        $tree = $this->ottoTree('fixture-e2e');
        $job  = $this->seedDoneJob('job-001', $tree->id(), 'I1', 'Otto Searchable');

        // The full graph processed exactly the one fixture job, finalised it under ingested/ and
        // stored its single suggestion carrying the harvested cemetery fact.
        $facts = $this->assertSingleCemeteryRowFinalised(
            $this->drainService()->drain(null, 20),
            $tree,
            $job,
        );

        // The end-to-end discriminator: the funeral date the harvester extracted also survived the
        // whole composition-root chain into the persisted row.
        self::assertArrayHasKey('funeralDate', $facts);
        self::assertSame('2023-09-08', $facts['funeralDate']);

        // A second drain finds no claimable done job: nothing is ingested and the store is unchanged,
        // proving the finalisation is terminal and the run idempotent.
        $reRun = $this->drainService()->drain(null, 20);

        self::assertSame(0, $reRun->ingested);
        self::assertSame(0, $reRun->stored);
        self::assertSame(JobState::Ingested, $this->paths()->stateOf($job));
        self::assertCount(1, $this->storeFor($tree)->allPending(), 'the re-run added no row');
    }
}
