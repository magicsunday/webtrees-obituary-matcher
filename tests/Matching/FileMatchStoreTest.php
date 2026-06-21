<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use MagicSunday\ObituaryMatcher\Domain\Classification;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\MatchExplanation;
use MagicSunday\ObituaryMatcher\Domain\ObituaryRecord;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Test\Queue\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Behavioural tests for the file-based match store: per-key idempotency through the URL
 * normaliser and the terminal-row guard that prevents a rejected row from being resurrected.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileMatchStore::class)]
#[CoversClass(MatchStatus::class)]
#[CoversClass(StoredMatch::class)]
#[CoversClass(TerminalMatchTransitionException::class)]
#[UsesClass(ClassifiedMatch::class)]
#[UsesClass(Classification::class)]
#[UsesClass(MatchEngine::class)]
#[UsesClass(MatchExplanation::class)]
#[UsesClass(Classifier::class)]
final class FileMatchStoreTest extends TempDirTestCase
{
    /**
     * Two URLs differing only in a tracking parameter and a fragment collapse to one stored row,
     * and a row marked rejected stays rejected even when a later pending upsert is attempted.
     *
     * @return void
     */
    #[Test]
    public function upsertPendingIsIdempotentAndDoesNotClobberTerminalRows(): void
    {
        $store = new FileMatchStore($this->tmp);
        $m     = $this->storedMatch('I1', 'https://example.test/a?utm_source=x#frag', MatchStatus::Pending);

        $store->upsertPending($m);
        $store->upsertPending($m);                                 // tracking + fragment differ-only -> same key
        self::assertCount(
            1,
            $store->findByPerson('I1'),
            'tracking-param + fragment variants collapse to one normalised key',
        );

        // The rejection uses the bare URL (no tracking param), proving raw-vs-normalised key parity.
        $store->markRejected('I1', 'https://example.test/a', 'not a match');
        $store->upsertPending($m);                                 // must NOT resurrect a rejected row
        self::assertSame(MatchStatus::Rejected, $store->findByPerson('I1')[0]->status);
    }

    /**
     * A confirmed row is terminal against an automated re-ingest: a later pending upsert is a
     * silent no-op that reports false and the row stays Confirmed, while a first write reports true.
     *
     * @return void
     */
    #[Test]
    public function confirmedRowIsImmutableAgainstReingest(): void
    {
        $store = new FileMatchStore($this->tmp);

        // A first write of a fresh key reports true (a row was actually written).
        self::assertTrue(
            $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Confirmed)),
        );

        // A re-ingest over the now-terminal row reports false (no write happened) ...
        self::assertFalse(
            $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending)),
        );

        // ... and the persisted row is unchanged.
        self::assertSame(MatchStatus::Confirmed, $store->findByPerson('I1')[0]->status);
    }

    /**
     * An EXPLICIT rejection of a Confirmed row is refused observably: it throws
     * TerminalMatchTransitionException rather than silently dropping the operation, and the row
     * stays Confirmed after the throw.
     *
     * @return void
     */
    #[Test]
    public function explicitRejectionOfConfirmedRowThrowsAndLeavesItConfirmed(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Confirmed));

        try {
            $store->markRejected('I1', 'https://example.test/a', 'changed my mind');
            self::fail('Rejecting a confirmed row must throw TerminalMatchTransitionException.');
        } catch (TerminalMatchTransitionException $exception) {
            self::assertStringContainsString('already confirmed', $exception->getMessage());
        }

        self::assertSame(MatchStatus::Confirmed, $store->findByPerson('I1')[0]->status);
    }

    /**
     * Re-rejecting an already-Rejected row is an idempotent no-op: it neither throws nor changes the
     * stored row.
     *
     * @return void
     */
    #[Test]
    public function reRejectingARejectedRowIsAnIdempotentNoOp(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->markRejected('I1', 'https://example.test/a', 'not a match');
        $store->markRejected('I1', 'https://example.test/a', 'still not a match');

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
        self::assertSame('not a match', $rows[0]->reason);
    }

    /**
     * Re-ingesting a non-terminal row updates it in place rather than appending a duplicate.
     *
     * @return void
     */
    #[Test]
    public function upsertPendingUpdatesNonTerminalRowInPlace(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));
        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Uncertain));

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Uncertain, $rows[0]->status);
    }

    /**
     * findByPerson returns only the requesting candidate's rows and nothing for an unknown one.
     *
     * @return void
     */
    #[Test]
    public function findByPersonIsolatesCandidates(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));
        $store->upsertPending($this->storedMatch('I2', 'https://example.test/b', MatchStatus::Pending));

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame('I1', $rows[0]->personId);

        self::assertCount(0, $store->findByPerson('I9'));
    }

    /**
     * allPending returns every Pending row across candidates and excludes terminal rows.
     *
     * @return void
     */
    #[Test]
    public function allPendingReturnsOnlyPendingRows(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));
        $store->upsertPending($this->storedMatch('I2', 'https://example.test/b', MatchStatus::Pending));
        $store->markRejected('I2', 'https://example.test/b', 'no');

        $pending = $store->allPending();
        self::assertCount(1, $pending);
        self::assertSame('I1', $pending[0]->personId);
    }

    /**
     * markRejected persists a rejected row even when no prior suggestion exists for the key.
     *
     * @return void
     */
    #[Test]
    public function markRejectedSynthesisesRowForUnknownKey(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->markRejected('I9', 'https://example.test/unseen', 'spam');

        $rows = $store->findByPerson('I9');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
        self::assertSame('spam', $rows[0]->reason);
    }

    /**
     * Builds a StoredMatch carrying a genuine ClassifiedMatch::toArray() payload produced by the
     * pure scoring engine, so the persisted shape mirrors what the ingest pipeline will store.
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source URL.
     * @param MatchStatus $status      The status to stamp on the stored match.
     *
     * @return StoredMatch The stored match with a real engine payload.
     */
    private function storedMatch(string $personId, string $obituaryUrl, MatchStatus $status): StoredMatch
    {
        $candidate = new PersonCandidate(
            $personId,
            Gender::Male,
            new PersonName(['Rainer'], null, 'Vorbild', null),
            ObituaryDateParser::parse('02.08.1962'),
            null,
            [new Place('Musterstadt')],
            DateRange::unknown(),
        );

        $notice = new ObituaryRecord(
            'Rainer Vorbild',
            ObituaryNameParser::parse('Rainer Vorbild'),
            ObituaryDateParser::parse('02.08.1962'),
            ObituaryDateParser::parse('23.03.2026'),
            new Place('Musterstadt'),
            $obituaryUrl,
            'example.test',
        );

        $result         = (new MatchEngine())->score($candidate, $notice);
        $classification = (new Classifier())->classify($result, [$result]);
        $classified     = new ClassifiedMatch($result, $classification);

        return new StoredMatch($personId, $obituaryUrl, $status, $classified->toArray());
    }
}
