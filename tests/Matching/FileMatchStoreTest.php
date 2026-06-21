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
use MagicSunday\ObituaryMatcher\Matching\CorruptMatchRowException;
use MagicSunday\ObituaryMatcher\Matching\FileMatchStore;
use MagicSunday\ObituaryMatcher\Matching\MatchStatus;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Test\Queue\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function file_put_contents;
use function glob;
use function mkdir;

/**
 * Behavioural tests for the file-based match store: per-key idempotency through the URL
 * normaliser and the terminal-row guard that prevents a rejected row from being resurrected.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FileMatchStore::class)]
#[CoversClass(CorruptMatchRowException::class)]
#[CoversClass(MatchStatus::class)]
#[CoversClass(StoredMatch::class)]
#[CoversClass(TerminalMatchTransitionException::class)]
#[UsesClass(AtomicFile::class)]
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
     * Re-rejecting an already-Rejected row with the SAME reason is an idempotent no-op (it neither
     * throws nor changes the stored row), while re-rejecting with a DIFFERENT reason stays terminal
     * but updates the reason in place — so a reviewer can correct their rejection rationale.
     *
     * @return void
     */
    #[Test]
    public function reRejectingARejectedRowKeepsOneRowAndUpdatesOnlyADifferentReason(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->markRejected('I1', 'https://example.test/a', 'not a match');

        // (a) Same reason: a harmless idempotent no-op — still one Rejected row with the same reason.
        $store->markRejected('I1', 'https://example.test/a', 'not a match');

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
        self::assertSame('not a match', $rows[0]->reason);

        // (b) Different reason: still one terminal Rejected row, but the reason is re-written.
        $store->markRejected('I1', 'https://example.test/a', 'duplicate of another notice');

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows);
        self::assertSame(MatchStatus::Rejected, $rows[0]->status);
        self::assertSame('duplicate of another notice', $rows[0]->reason);
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
     * A StoredMatch survives a toArray() -> fromArray() -> toArray() round-trip byte-for-byte, so
     * the persisted shape is pinned independently of the file store: a future engine field that
     * drifts the alias would break this identity.
     *
     * @return void
     */
    #[Test]
    public function storedMatchRoundTripsThroughArray(): void
    {
        $original = $this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending);

        $reconstructed = StoredMatch::fromArray($original->toArray());

        self::assertSame($original->toArray(), $reconstructed->toArray());
        self::assertSame($original->personId, $reconstructed->personId);
        self::assertSame($original->obituaryUrl, $reconstructed->obituaryUrl);
        self::assertSame($original->status, $reconstructed->status);
    }

    /**
     * A single corrupt row in the store directory must not hide the valid rows: a directory scan
     * (allPending / findByPerson) skips the poison row and still surfaces every well-formed row.
     *
     * @return void
     */
    #[Test]
    public function aCorruptRowIsSkippedAndDoesNotHideValidRows(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));

        // Drop a malformed *.json next to the valid row: it decodes but lacks the required keys
        // (a wrong-SHAPE row that throws CorruptMatchRowException on reconstruction).
        file_put_contents($this->tmp . '/corrupt.json', '{"not":"a stored match"}');

        // Drop a TRUNCATED / non-JSON row too: AtomicFile is explicitly not crash-durable, so a
        // half-written file is the most likely real corruption. It throws a raw JsonException on
        // decode, which is NOT a CorruptMatchRowException — the scan must still tolerate it.
        file_put_contents($this->tmp . '/deadbeef.json', '{ "personId": "I9", truncated');

        // Drop a VALID-JSON but top-level-scalar row (a bare "null"): json_decode returns null, not
        // an array, which would throw a TypeError from AtomicFile::readJsonCapped's ": array" return
        // type — NOT a JsonException/RuntimeException — and so crash the whole scan unless the reader
        // converts it into a RuntimeException the catch can isolate.
        file_put_contents($this->tmp . '/scalar.json', 'null');

        $pending = $store->allPending();
        self::assertCount(1, $pending, 'the poison row is skipped, the valid one survives');
        self::assertSame('I1', $pending[0]->personId);

        $found = $store->findByPerson('I1');
        self::assertCount(1, $found);
        self::assertSame('I1', $found[0]->personId);
    }

    /**
     * A store directory whose path contains a glob metacharacter is scanned correctly: glob() would
     * interpret the whole path as a pattern and silently return nothing (the review queue would look
     * empty), so the FilesystemIterator scan must still surface the stored row.
     *
     * @return void
     */
    #[Test]
    public function scansAStoreDirectoryWhosePathContainsAGlobMetacharacter(): void
    {
        $dir = $this->tmp . '/store[1]';
        mkdir($dir, 0o700, true);

        $store = new FileMatchStore($dir);
        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows, 'a glob metacharacter in the dir path must not hide the row');
        self::assertSame('I1', $rows[0]->personId);

        self::assertCount(1, $store->allPending());
    }

    /**
     * An in-flight atomic temp file (named "<key>.json.tmp.<uniqid>") sitting in the store directory
     * is NOT scanned as a row: its extension is the uniqid, not "json", so the FilesystemIterator
     * extension filter excludes it exactly as the old "*.json" glob did.
     *
     * @return void
     */
    #[Test]
    public function anInFlightTempFileIsNotScanned(): void
    {
        $store = new FileMatchStore($this->tmp);
        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));

        // A leftover atomic temp file (the shape AtomicFile writes before the rename) must be ignored.
        file_put_contents($this->tmp . '/deadbeef.json.tmp.abc123', '{"not":"a stored match"}');

        $rows = $store->allPending();
        self::assertCount(1, $rows, 'the in-flight *.json.tmp.* file is excluded from the scan');
        self::assertSame('I1', $rows[0]->personId);
    }

    /**
     * A single-key read of a corrupt row stays fail-loud: it throws CorruptMatchRowException rather
     * than being silently skipped, so an upsert/reject against a poisoned key surfaces the problem.
     *
     * @return void
     */
    #[Test]
    public function aSingleKeyReadOfACorruptRowThrows(): void
    {
        $store = new FileMatchStore($this->tmp);

        // Plant a corrupt row exactly where the key for (I1, url) would resolve, then upsert that key.
        $match = $this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending);
        $store->upsertPending($match);

        // Overwrite the stored row with a malformed payload so the next read of the SAME key fails.
        $paths = glob($this->tmp . '/*.json');

        foreach (($paths === false) ? [] : $paths as $path) {
            file_put_contents($path, '{"broken":true}');
        }

        $this->expectException(CorruptMatchRowException::class);
        $store->upsertPending($match);
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
