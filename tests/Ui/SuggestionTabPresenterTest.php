<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Ui;

use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Ui\SuggestionTabPresenter;
use MagicSunday\ObituaryMatcher\Ui\SuggestionViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

/**
 * Behavioural tests for the suggestion tab presenter: it keeps only the non-terminal stored matches,
 * projects them into view models and memoises the store read per XREF for one request.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SuggestionTabPresenter::class)]
#[UsesClass(SuggestionViewModel::class)]
#[UsesClass(StoredMatch::class)]
#[UsesClass(MatchStatus::class)]
final class SuggestionTabPresenterTest extends TestCase
{
    /**
     * Builds an in-memory match store fake over the given rows that counts its read calls.
     *
     * @param list<StoredMatch> $rows The rows the fake store returns.
     *
     * @return MatchStore The in-memory store fake.
     */
    private function store(array $rows): MatchStore
    {
        return new class($rows) implements MatchStore {
            /**
             * Constructor.
             *
             * @param list<StoredMatch> $rows The rows the fake store returns.
             */
            public function __construct(private array $rows)
            {
            }

            /**
             * Returns every stored match for the given candidate.
             *
             * @param string $personId The candidate identifier.
             *
             * @return list<StoredMatch> The stored matches.
             */
            public function findByPerson(string $personId): array
            {
                return array_values(
                    array_filter(
                        $this->rows,
                        static fn (StoredMatch $r): bool => $r->personId === $personId
                    )
                );
            }

            /**
             * Stores a pending suggestion.
             *
             * @param StoredMatch $match The suggestion to store.
             *
             * @return bool Always true in this fake.
             */
            public function upsertPending(StoredMatch $match): bool
            {
                return true;
            }

            /**
             * Returns every pending stored match.
             *
             * @return list<StoredMatch> The pending matches.
             */
            public function allPending(): array
            {
                return $this->rows;
            }

            /**
             * Marks the row as rejected.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The rejection reason, if any.
             *
             * @return void
             */
            public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Returns the single stored match for the given key (unused by these tests).
             *
             * @param string $personId The candidate identifier.
             * @param string $rowKey   The canonical row key.
             *
             * @return StoredMatch|null Always null in this fake.
             */
            public function findOne(string $personId, string $rowKey): ?StoredMatch
            {
                return null;
            }

            /**
             * Marks the row as uncertain (no-op in this fake).
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The reviewer note, if any.
             *
             * @return void
             */
            public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Marks the row confirmed (no-op in this fake).
             *
             * @param string    $personId    The candidate identifier.
             * @param string    $obituaryUrl The source notice URL.
             * @param WriteBack $writeBack   The write-back IDs.
             *
             * @return bool Always false in this fake.
             */
            public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
            {
                return false;
            }
        };
    }

    /**
     * Builds a stored match for the given candidate and status.
     *
     * @param string      $xref   The candidate identifier.
     * @param MatchStatus $status The lifecycle status.
     *
     * @return StoredMatch The stored match.
     */
    private function row(string $xref, MatchStatus $status): StoredMatch
    {
        return new StoredMatch($xref, 'https://trauer.example/a', $status, [
            'personId'       => $xref,
            'obituaryUrl'    => 'https://trauer.example/a',
            'score'          => 80,
            'hardConflict'   => false,
            'signals'        => [],
            'extractedFacts' => [],
            'classification' => 'probable',
            'ambiguous'      => false,
            'runnerUp'       => null,
            'review'         => null,
        ]);
    }

    /**
     * A non-terminal match makes the tab report content.
     *
     * @return void
     */
    #[Test]
    public function hasContentTrueForNonTerminal(): void
    {
        self::assertTrue(
            (new SuggestionTabPresenter($this->store([$this->row('I1', MatchStatus::Pending)])))->hasContent('I1')
        );
    }

    /**
     * A terminal-only candidate and an empty store both report no content.
     *
     * @return void
     */
    #[Test]
    public function hasContentFalseForTerminalOnlyAndEmpty(): void
    {
        self::assertFalse(
            (new SuggestionTabPresenter($this->store([$this->row('I1', MatchStatus::Confirmed)])))->hasContent('I1')
        );
        self::assertFalse(
            (new SuggestionTabPresenter($this->store([])))->hasContent('I9')
        );
    }

    /**
     * The presenter projects each kept row into a view model.
     *
     * @return void
     */
    #[Test]
    public function suggestionsForReturnsViewModels(): void
    {
        $vms = (new SuggestionTabPresenter($this->store([$this->row('I1', MatchStatus::Uncertain)])))->suggestionsFor('I1');

        self::assertCount(1, $vms);
        self::assertSame('uncertain', $vms[0]->statusKey);
    }

    /**
     * The store is read only once per XREF for the lifetime of the presenter.
     *
     * @return void
     */
    #[Test]
    public function memoisesStoreReadPerXref(): void
    {
        $rows = [$this->row('I1', MatchStatus::Pending)];

        $store = new class($rows) implements MatchStore {
            /**
             * The number of times the store was read.
             *
             * @var int
             */
            public int $calls = 0;

            /**
             * Constructor.
             *
             * @param list<StoredMatch> $rows The rows the fake store returns.
             */
            public function __construct(private array $rows)
            {
            }

            /**
             * Returns every stored match for the given candidate.
             *
             * @param string $personId The candidate identifier.
             *
             * @return list<StoredMatch> The stored matches.
             */
            public function findByPerson(string $personId): array
            {
                ++$this->calls;

                return array_values(
                    array_filter(
                        $this->rows,
                        static fn (StoredMatch $r): bool => $r->personId === $personId
                    )
                );
            }

            /**
             * Stores a pending suggestion.
             *
             * @param StoredMatch $match The suggestion to store.
             *
             * @return bool Always true in this fake.
             */
            public function upsertPending(StoredMatch $match): bool
            {
                return true;
            }

            /**
             * Returns every pending stored match.
             *
             * @return list<StoredMatch> The pending matches.
             */
            public function allPending(): array
            {
                return $this->rows;
            }

            /**
             * Marks the row as rejected.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The rejection reason, if any.
             *
             * @return void
             */
            public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Returns the single stored match for the given key (unused by these tests).
             *
             * @param string $personId The candidate identifier.
             * @param string $rowKey   The canonical row key.
             *
             * @return StoredMatch|null Always null in this fake.
             */
            public function findOne(string $personId, string $rowKey): ?StoredMatch
            {
                return null;
            }

            /**
             * Marks the row as uncertain (no-op in this fake).
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The reviewer note, if any.
             *
             * @return void
             */
            public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Marks the row confirmed (no-op in this fake).
             *
             * @param string    $personId    The candidate identifier.
             * @param string    $obituaryUrl The source notice URL.
             * @param WriteBack $writeBack   The write-back IDs.
             *
             * @return bool Always false in this fake.
             */
            public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
            {
                return false;
            }
        };

        $presenter = new SuggestionTabPresenter($store);

        $presenter->hasContent('I1');
        $presenter->suggestionsFor('I1');
        $presenter->hasContent('I1');

        self::assertSame(1, $store->calls);
    }

    /**
     * An empty result is memoised too: a candidate with no kept matches reads the store exactly once
     * across repeated lookups, because the cached empty array still registers as set (`isset([])`).
     *
     * @return void
     */
    #[Test]
    public function memoisesEmptyStoreReadPerXref(): void
    {
        $store = new class implements MatchStore {
            /**
             * The number of times the store was read.
             *
             * @var int
             */
            public int $calls = 0;

            /**
             * Returns every stored match for the given candidate.
             *
             * @param string $personId The candidate identifier.
             *
             * @return list<StoredMatch> The stored matches (always empty in this fake).
             */
            public function findByPerson(string $personId): array
            {
                ++$this->calls;

                return [];
            }

            /**
             * Stores a pending suggestion.
             *
             * @param StoredMatch $match The suggestion to store.
             *
             * @return bool Always true in this fake.
             */
            public function upsertPending(StoredMatch $match): bool
            {
                return true;
            }

            /**
             * Returns every pending stored match.
             *
             * @return list<StoredMatch> The pending matches (always empty in this fake).
             */
            public function allPending(): array
            {
                return [];
            }

            /**
             * Marks the row as rejected.
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The rejection reason, if any.
             *
             * @return void
             */
            public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Returns the single stored match for the given key (unused by these tests).
             *
             * @param string $personId The candidate identifier.
             * @param string $rowKey   The canonical row key.
             *
             * @return StoredMatch|null Always null in this fake.
             */
            public function findOne(string $personId, string $rowKey): ?StoredMatch
            {
                return null;
            }

            /**
             * Marks the row as uncertain (no-op in this fake).
             *
             * @param string      $personId    The candidate identifier.
             * @param string      $obituaryUrl The source notice URL.
             * @param string|null $reason      The reviewer note, if any.
             *
             * @return void
             */
            public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
            {
            }

            /**
             * Marks the row confirmed (no-op in this fake).
             *
             * @param string    $personId    The candidate identifier.
             * @param string    $obituaryUrl The source notice URL.
             * @param WriteBack $writeBack   The write-back IDs.
             *
             * @return bool Always false in this fake.
             */
            public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
            {
                return false;
            }
        };

        $presenter = new SuggestionTabPresenter($store);

        $presenter->hasContent('I9');
        $presenter->suggestionsFor('I9');
        $presenter->hasContent('I9');

        self::assertSame(1, $store->calls);
    }
}
