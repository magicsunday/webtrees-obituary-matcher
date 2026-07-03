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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Sentinel for the data provider marking a payload key that must be UNSET (absent) rather than set
     * to a value — distinct from any legitimate payload value (including null).
     *
     * @var string
     */
    private const string ABSENT = "\0__absent__\0";

    /**
     * Builds a presenter entry wrapping a {@see StoredMatch} with the given status and score.
     *
     * @param string      $id           The candidate XREF.
     * @param int         $score        The match score for the payload.
     * @param MatchStatus $status       The lifecycle status.
     * @param string|null $reviewUrl    The non-terminal review URL.
     * @param bool        $hardConflict Whether the payload carries a hard conflict (#65 flag filter).
     * @param bool        $ambiguous    Whether the payload is ambiguous (#65 flag filter).
     * @param string|null $deathDate    The extracted ISO death date, or null (#65 death sort).
     * @param string|null $personName   The display name, or null for the default "Person <id>".
     *
     * @return array{match: StoredMatch, personName: string, personId: string, personUrl: string, reviewUrl: string|null}
     */
    private function entry(
        string $id,
        int $score,
        MatchStatus $status,
        ?string $reviewUrl = '/r/x',
        bool $hardConflict = false,
        bool $ambiguous = false,
        ?string $deathDate = '2023-09-04',
        ?string $personName = null,
    ): array {
        $match = [
            'personId'        => $id,
            'obituaryUrl'     => 'https://obituary.example/' . $id,
            'score'           => $score,
            'hardConflict'    => $hardConflict,
            'signals'         => [],
            'extractedFacts'  => $deathDate !== null ? ['deathDate' => $deathDate] : [],
            'noticeRelatives' => [],
            'classification'  => 'strong',
            'ambiguous'       => $ambiguous,
            'runnerUp'        => null,
            'review'          => null,
        ];

        return [
            'match'      => new StoredMatch($id, $match['obituaryUrl'], $status, $match),
            'personName' => $personName ?? 'Person ' . $id,
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
        ], 'all', 'all', 'score', 1);

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
        ], 'open', 'all', 'score', 1);

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
        ], 'bogus', 'all', 'score', 1);

        self::assertCount(2, $view->rows);
    }

    /**
     * The conflict flag filter keeps only rows carrying a hard conflict (#65).
     *
     * @return void
     */
    #[Test]
    public function filtersByTheConflictFlag(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending, '/r/x', hardConflict: true),
            $this->entry('I2', 80, MatchStatus::Pending),
        ], 'all', 'conflict', 'score', 1);

        self::assertCount(1, $view->rows);
        self::assertSame('I1', $view->rows[0]->personId);
        self::assertSame('conflict', $view->flagFilter);
    }

    /**
     * The ambiguous flag filter keeps only rows flagged ambiguous (#65).
     *
     * @return void
     */
    #[Test]
    public function filtersByTheAmbiguousFlag(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending, '/r/x', ambiguous: true),
            $this->entry('I2', 80, MatchStatus::Pending),
        ], 'all', 'ambiguous', 'score', 1);

        self::assertCount(1, $view->rows);
        self::assertSame('I1', $view->rows[0]->personId);
    }

    /**
     * The name sort orders by display name ascending, overriding the default score order (#65): the
     * higher-scored "Zeta" sorts AFTER "Alpha".
     *
     * @return void
     */
    #[Test]
    public function sortsByNameAscending(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 95, MatchStatus::Pending, '/r/x', personName: 'Zeta'),
            $this->entry('I2', 90, MatchStatus::Pending, '/r/x', personName: 'Alpha'),
        ], 'all', 'all', 'name', 1);

        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (WorklistRowView $r): string => $r->personName, $view->rows));
    }

    /**
     * The death sort orders by the extracted ISO death date ascending, a null-death row last, overriding
     * the default score order (#65).
     *
     * @return void
     */
    #[Test]
    public function sortsByDeathDateAscendingWithNullsLast(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending, '/r/x', deathDate: null),
            $this->entry('I2', 80, MatchStatus::Pending, '/r/x', deathDate: '2020-01-01'),
            $this->entry('I3', 70, MatchStatus::Pending, '/r/x', deathDate: '2010-01-01'),
        ], 'all', 'all', 'death', 1);

        self::assertSame(['I3', 'I2', 'I1'], array_map(static fn (WorklistRowView $r): string => $r->personId, $view->rows));
    }

    /**
     * An unknown flag or sort falls back to the defaults (all / score).
     *
     * @return void
     */
    #[Test]
    public function unknownFlagAndSortFallBackToDefaults(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('I1', 90, MatchStatus::Pending),
        ], 'all', 'bogus', 'bogus', 1);

        self::assertSame('all', $view->flagFilter);
        self::assertSame('score', $view->sort);
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
        ], 'all', 'all', 'score', 1);

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

        $view = (new WorklistPresenter())->build([$broken], 'all', 'all', 'score', 1);

        self::assertSame(0, $view->rows[0]->score);
    }

    /**
     * A tie on score is broken by personId in BYTE order, not numerically: a bare-numeric XREF pair
     * ('1000', '915') must sort '1000' before '915' (since '1' < '9'), matching the house byte-order
     * invariant ({@see \MagicSunday\ObituaryMatcher\Webtrees\CandidateRepository}'s SORT_STRING),
     * whereas a numeric `<=>` would (wrongly) place 915 before 1000.
     *
     * @return void
     */
    #[Test]
    public function tieIsBrokenByByteOrderNotNumerically(): void
    {
        $view = (new WorklistPresenter())->build([
            $this->entry('915', 50, MatchStatus::Pending),
            $this->entry('1000', 50, MatchStatus::Pending),
        ], 'all', 'all', 'score', 1);

        self::assertSame(['1000', '915'], array_map(static fn ($r) => $r->personId, $view->rows));
    }

    /**
     * A surviving row whose payload is malformed-but-array (a non-string/absent classification, a
     * non-array extractedFacts, a non-string deathDate) renders gracefully — the expected band and
     * death date — instead of throwing an Undefined-array-key notice or a TypeError that would take
     * down the entire worklist render. A malformed classification falls back to band "none"; a
     * malformed/absent extractedFacts or a non-string deathDate yields a null death date.
     *
     * @param mixed       $classification    The classification payload value (or the absence marker).
     * @param mixed       $extractedFacts    The extractedFacts payload value (or the absence marker).
     * @param string      $expectedBand      The band key the row must carry.
     * @param string|null $expectedDeathDate The death date the row must carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('malformedPayloadProvider')]
    public function malformedPayloadRendersGracefully(
        mixed $classification,
        mixed $extractedFacts,
        string $expectedBand,
        ?string $expectedDeathDate,
    ): void {
        $entry   = $this->entry('I1', 90, MatchStatus::Pending);
        $payload = $entry['match']->match;

        if ($classification === self::ABSENT) {
            unset($payload['classification']);
        } else {
            $payload['classification'] = $classification;
        }

        if ($extractedFacts === self::ABSENT) {
            unset($payload['extractedFacts']);
        } else {
            $payload['extractedFacts'] = $extractedFacts;
        }

        /** @var ClassifiedMatchArray $payload */
        $entry['match'] = new StoredMatch('I1', $entry['match']->obituaryUrl, MatchStatus::Pending, $payload);

        $view = (new WorklistPresenter())->build([$entry], 'all', 'all', 'score', 1);

        self::assertSame($expectedBand, $view->rows[0]->bandKey);
        self::assertSame($expectedDeathDate, $view->rows[0]->deathDate);
    }

    /**
     * Malformed-but-array payload shapes that must each degrade gracefully in {@see toRow()}, paired
     * with the band and death date the resulting row must carry.
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: string, 3: string|null}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'classification absent'     => [self::ABSENT, ['deathDate' => '2023-09-04'], 'none', '04.09.2023'],
            'classification non-string' => [42, ['deathDate' => '2023-09-04'], 'none', '04.09.2023'],
            'classification array'      => [['strong'], ['deathDate' => '2023-09-04'], 'none', '04.09.2023'],
            'extractedFacts non-array'  => ['strong', 'not-an-array', 'strong', null],
            'extractedFacts absent'     => ['strong', self::ABSENT, 'strong', null],
            'deathDate non-string'      => ['strong', ['deathDate' => 20230904], 'strong', null],
            'all malformed at once'     => [self::ABSENT, 99, 'none', null],
        ];
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

        $page1 = $presenter->build($entries, 'all', 'all', 'score', 1);
        self::assertCount(WorklistPresenter::WORKLIST_PAGE_SIZE, $page1->rows);
        self::assertSame(3, $page1->totalPages);
        self::assertSame(120, $page1->totalFiltered);

        $clamped = $presenter->build($entries, 'all', 'all', 'score', 99);
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
        ], 'all', 'all', 'score', 1);

        $byId = [];

        foreach ($view->rows as $row) {
            $byId[$row->personId] = $row->reviewUrl;
        }
        self::assertNull($byId['I1']);
        self::assertNotNull($byId['I2']);
    }

    /**
     * The revert URL is carried only for a Confirmed row (the only status whose facts can be undone) and
     * is null for every non-Confirmed row.
     *
     * @return void
     */
    #[Test]
    public function revertUrlIsSetOnlyForConfirmedRows(): void
    {
        $confirmed = $this->entry('I1', 90, MatchStatus::Confirmed);
        $pending   = $this->entry('I2', 80, MatchStatus::Pending);
        $rejected  = $this->entry('I3', 70, MatchStatus::Rejected);

        $view = (new WorklistPresenter())->build([$confirmed, $pending, $rejected], 'all', 'all', 'score', 1);

        $byId = [];

        foreach ($view->rows as $row) {
            $byId[$row->personId] = $row;
        }

        self::assertSame($confirmed['match']->obituaryUrl, $byId['I1']->revertObituaryUrl);
        // Explicitly assert BOTH a non-terminal (Pending) AND the other terminal status (Rejected) are null —
        // Rejected must not get a revert affordance any more than Pending does.
        self::assertNull($byId['I2']->revertObituaryUrl);
        self::assertNull($byId['I3']->revertObituaryUrl);
    }

    /**
     * An empty input yields an empty view with a single (clamped) page.
     *
     * @return void
     */
    #[Test]
    public function emptyInputYieldsEmptyViewWithOnePage(): void
    {
        $view = (new WorklistPresenter())->build([], 'all', 'all', 'score', 1);

        self::assertSame([], $view->rows);
        self::assertSame(['total' => 0, 'open' => 0, 'confirmed' => 0, 'rejected' => 0, 'uncertain' => 0], $view->counts);
        self::assertSame(1, $view->totalPages);
    }
}
