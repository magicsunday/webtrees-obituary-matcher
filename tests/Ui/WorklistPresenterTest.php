<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Ui\WorklistPresenter;
use MagicSunday\ObituaryMatcher\Ui\WorklistRowView;
use MagicSunday\ObituaryMatcher\Ui\WorklistView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sprintf;

/**
 * Pure-unit suite for the webtrees-free worklist presenter: counts over every surviving entry, the
 * status filter (with unknown-falls-back-to-all), the score-desc/personId-asc sort, the defensive
 * score read, paging with page clamping, the verbatim review-URL copy and the empty-input baseline.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(WorklistPresenter::class)]
#[UsesClass(WorklistView::class)]
#[UsesClass(WorklistRowView::class)]
final class WorklistPresenterTest extends TestCase
{
    /**
     * Builds a presenter entry wrapping a {@see StoredMatch} with the given status and score.
     *
     * @param string      $id        The candidate XREF.
     * @param int         $score     The match score for the payload.
     * @param MatchStatus $status    The lifecycle status.
     * @param string|null $reviewUrl The non-terminal review URL.
     *
     * @return array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}
     */
    private function entry(string $id, int $score, MatchStatus $status, ?string $reviewUrl = '/r/x'): array
    {
        $match = [
            'personId'       => $id,
            'obituaryUrl'    => 'https://obituary.example/' . $id,
            'score'          => $score,
            'hardConflict'   => false,
            'signals'        => [],
            'extractedFacts' => ['deathDate' => '2023-09-04'],
            'classification' => 'strong',
            'ambiguous'      => false,
            'runnerUp'       => null,
            'review'         => null,
        ];

        return [
            'match'      => new StoredMatch($id, $match['obituaryUrl'], $status, $match),
            'personName' => 'Person ' . $id,
            'personId'   => $id,
            'personUrl'  => '/p/' . $id,
            'reviewUrl'  => ($status === MatchStatus::Pending) || ($status === MatchStatus::Uncertain) ? $reviewUrl : null,
        ];
    }

    /**
     * Returns a copy of the payload with its score key removed, as the trusted shape. The parameter is
     * typed as a mixed-valued map so the static shape is erased: PHPStan then no longer tracks the
     * missing key and the re-asserted shape models a malformed-but-array on-disk JSON row.
     *
     * @param array<string, mixed> $payload The original payload.
     *
     * @return ClassifiedMatchArray The payload without its score key.
     */
    private function withoutScore(array $payload): array
    {
        unset($payload['score']);

        /** @var ClassifiedMatchArray $payload */
        return $payload;
    }

    /**
     * The counts tally every surviving entry by status, regardless of the active filter.
     *
     * @return void
     */
    #[Test]
    public function countsAreComputedOverAllSurvivingEntries(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending),
            $this->entry('I2', 80, MatchStatus::Confirmed),
            $this->entry('I3', 70, MatchStatus::Rejected),
            $this->entry('I4', 60, MatchStatus::Uncertain),
        ], 'all', 1);

        self::assertSame(['total' => 4, 'open' => 1, 'confirmed' => 1, 'rejected' => 1, 'uncertain' => 1], $view->counts);
    }

    /**
     * The "open" filter returns only Pending rows; Uncertain is a separate status.
     *
     * @return void
     */
    #[Test]
    public function filterOpenReturnsOnlyPending(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending),
            $this->entry('I2', 80, MatchStatus::Uncertain),
        ], 'open', 1);

        self::assertCount(1, $view->rows);
        self::assertSame('I1', $view->rows[0]->personId);
    }

    /**
     * An unknown filter value falls back to "all".
     *
     * @return void
     */
    #[Test]
    public function unknownFilterFallsBackToAll(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending),
            $this->entry('I2', 80, MatchStatus::Rejected),
        ], 'bogus', 1);

        self::assertCount(2, $view->rows);
    }

    /**
     * Rows sort by score descending, tie-broken by personId ascending.
     *
     * @return void
     */
    #[Test]
    public function sortsByScoreDescThenPersonIdAsc(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I2', 50, MatchStatus::Pending),
            $this->entry('I1', 50, MatchStatus::Pending),
            $this->entry('I3', 90, MatchStatus::Pending),
        ], 'all', 1);

        self::assertSame(['I3', 'I1', 'I2'], array_map(static fn ($r) => $r->personId, $view->rows));
    }

    /**
     * A missing or non-int score collapses to 0.
     *
     * @return void
     */
    #[Test]
    public function missingOrNonIntScoreBecomesZero(): void
    {
        $broken = $this->entry('I1', 0, MatchStatus::Pending);

        // Erase the static shape to a mixed-valued map, drop the score, then re-assert the trusted
        // shape: the on-disk JSON payload may be malformed-but-array, and the presenter reads the
        // score through its own mixed-erasing read() — this mirrors that boundary in the fixture.
        $payload = $this->withoutScore($broken['match']->match);

        $broken['match'] = new StoredMatch('I1', $broken['match']->obituaryUrl, MatchStatus::Pending, $payload);

        $view = (new WorklistPresenter())->build([$broken], 'all', 1);

        self::assertSame(0, $view->rows[0]->score);
    }

    /**
     * The page size caps each page and a page past the end clamps to the last page.
     *
     * @return void
     */
    #[Test]
    public function paginatesAndClampsPagePastTheEnd(): void
    {
        $entries = [];

        for ($i = 1; $i <= 120; ++$i) {
            $entries[] = $this->entry(sprintf('I%03d', $i), 200 - $i, MatchStatus::Pending);
        }
        $presenter = new WorklistPresenter();

        $page1 = $presenter->build($entries, 'all', 1);
        self::assertCount(WorklistPresenter::WORKLIST_PAGE_SIZE, $page1->rows);
        self::assertSame(3, $page1->totalPages);
        self::assertSame(120, $page1->totalFiltered);

        $clamped = $presenter->build($entries, 'all', 99);
        self::assertSame(3, $clamped->page);
        self::assertCount(20, $clamped->rows); // 120 - 2*50
    }

    /**
     * A terminal row carries its review URL through verbatim as null.
     *
     * @return void
     */
    #[Test]
    public function terminalEntryReviewUrlIsCopiedAsNull(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Confirmed),
            $this->entry('I2', 80, MatchStatus::Pending),
        ], 'all', 1);

        $byId = [];

        foreach ($view->rows as $row) {
            $byId[$row->personId] = $row->reviewUrl;
        }
        self::assertNull($byId['I1']);
        self::assertNotNull($byId['I2']);
    }

    /**
     * An empty input yields an empty view with a single (clamped) page.
     *
     * @return void
     */
    #[Test]
    public function emptyInputYieldsEmptyViewWithOnePage(): void
    {
        $view = (new WorklistPresenter())->build([], 'all', 1);

        self::assertSame([], $view->rows);
        self::assertSame(['total' => 0, 'open' => 0, 'confirmed' => 0, 'rejected' => 0, 'uncertain' => 0], $view->counts);
        self::assertSame(1, $view->totalPages);
    }
}
