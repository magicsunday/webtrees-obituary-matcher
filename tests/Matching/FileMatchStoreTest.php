<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Matching;

use Closure;
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
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;
use MagicSunday\ObituaryMatcher\Matching\TerminalMatchTransitionException;
use MagicSunday\ObituaryMatcher\Parsing\ObituaryDateParser;
use MagicSunday\ObituaryMatcher\Queue\AtomicFile;
use MagicSunday\ObituaryMatcher\Scoring\Classifier;
use MagicSunday\ObituaryMatcher\Scoring\MatchEngine;
use MagicSunday\ObituaryMatcher\Support\ObituaryNameParser;
use MagicSunday\ObituaryMatcher\Test\Support\TempDirTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function clearstatcache;
use function file_put_contents;
use function filemtime;
use function glob;
use function hash;
use function hash_file;
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
#[UsesClass(StoredMatchKey::class)]
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
     * findOne returns the row written for the same (person, url), addressed by its row key.
     *
     * @return void
     */
    #[Test]
    public function findOneResolvesTheRowUpsertWrote(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $found = $store->findOne('I1', StoredMatchKey::fromUrl($url));

        self::assertInstanceOf(StoredMatch::class, $found);
        self::assertSame('I1', $found->personId);
        self::assertSame(MatchStatus::Pending, $found->status);
    }

    /**
     * findOne is fail-loud on a corrupt row: a wrong-shape JSON file planted exactly where the key
     * for (I1, url) resolves throws CorruptMatchRowException rather than masquerading as "not found".
     * This pins the single-key read contract directly on findOne (the upsert path proves the same
     * readRow semantics from the other side).
     *
     * @return void
     */
    #[Test]
    public function findOneOfACorruptRowThrows(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $path = $this->tmp . '/' . hash('sha256', 'I1' . "\0" . StoredMatchKey::fromUrl($url)) . '.json';
        file_put_contents($path, '{"not":"a stored match"}');

        $this->expectException(CorruptMatchRowException::class);

        $store->findOne('I1', StoredMatchKey::fromUrl($url));
    }

    /**
     * findOne returns null for an unknown key.
     *
     * @return void
     */
    #[Test]
    public function findOneReturnsNullForUnknownKey(): void
    {
        $store = new FileMatchStore($this->tmp);

        self::assertNull($store->findOne('I1', StoredMatchKey::fromUrl('https://trauer.example/missing')));
    }

    /**
     * markUncertain moves a pending row to uncertain.
     *
     * @return void
     */
    #[Test]
    public function markUncertainMovesPendingToUncertain(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $store->markUncertain('I1', $url, null);

        self::assertSame(MatchStatus::Uncertain, $store->findOne('I1', StoredMatchKey::fromUrl($url))?->status);
    }

    /**
     * Re-marking an already-uncertain row with the same reason does not rewrite the file.
     *
     * @return void
     */
    #[Test]
    public function markUncertainIsIdempotentWithoutRewrite(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));
        $store->markUncertain('I1', $url, null);

        $path   = $this->tmp . '/' . hash('sha256', 'I1' . "\0" . StoredMatchKey::fromUrl($url)) . '.json';
        $before = hash_file('sha256', $path);
        clearstatcache(true, $path);
        $mtime = filemtime($path);

        $store->markUncertain('I1', $url, null);

        clearstatcache(true, $path);
        // Content hash is the primary, FS-resolution-independent assertion; mtime is secondary.
        self::assertSame($before, hash_file('sha256', $path), 'idempotent uncertain must not change the file content');
        self::assertSame($mtime, filemtime($path), 'idempotent uncertain must not rewrite the row');
        self::assertSame(MatchStatus::Uncertain, $store->findOne('I1', StoredMatchKey::fromUrl($url))?->status);
    }

    /**
     * Re-marking an already-uncertain row with a DIFFERENT reason rewrites the row in place: the
     * status stays Uncertain, the reason is updated and — the inverse of the idempotency test — the
     * file content hash changes. This pins the rewrite branch the same-reason no-op skips.
     *
     * @return void
     */
    #[Test]
    public function markUncertainUpdatesReasonWhenDifferent(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $store->markUncertain('I1', $url, 'first');

        $path   = $this->tmp . '/' . hash('sha256', 'I1' . "\0" . StoredMatchKey::fromUrl($url)) . '.json';
        $before = hash_file('sha256', $path);

        $store->markUncertain('I1', $url, 'revised');

        $found = $store->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $found);
        self::assertSame(MatchStatus::Uncertain, $found->status);
        self::assertSame('revised', $found->reason);
        self::assertNotSame($before, hash_file('sha256', $path), 'a different reason must rewrite the file content');
    }

    /**
     * markUncertain on a vanished row is a no-op: it creates no synthetic uncertain row.
     *
     * @return void
     */
    #[Test]
    public function markUncertainOnMissingRowIsANoOp(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->markUncertain('I1', 'https://trauer.example/missing', null);

        self::assertNull($store->findOne('I1', StoredMatchKey::fromUrl('https://trauer.example/missing')));
    }

    /**
     * markUncertain refuses a terminal row, whether it is rejected OR confirmed (spec §8: you cannot
     * transition out of a terminal state). The two terminal states are reached through their genuine
     * store paths — markRejected for the rejected row, a fresh-key Confirmed upsert for the confirmed
     * row — so each branch of the terminal guard is exercised.
     *
     * @param Closure(FileMatchStore, string): void $seedTerminal Drives the store into the terminal state under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('terminalRowSeedProvider')]
    public function markUncertainThrowsOnTerminalRow(Closure $seedTerminal): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';

        $seedTerminal($store, $url);

        $this->expectException(TerminalMatchTransitionException::class);

        $store->markUncertain('I1', $url, null);
    }

    /**
     * Provides a seeding closure per terminal status, so markUncertain's guard is pinned against both
     * the rejected and the confirmed terminal rows.
     *
     * @return iterable<string, array{Closure(FileMatchStore, string): void}> The terminal seeds.
     */
    public static function terminalRowSeedProvider(): iterable
    {
        yield 'rejected' => [
            static function (FileMatchStore $store, string $url): void {
                $store->upsertPending(self::terminalSeedRow($url, MatchStatus::Pending));
                $store->markRejected('I1', $url, null);
            },
        ];

        yield 'confirmed' => [
            static function (FileMatchStore $store, string $url): void {
                // A fresh-key Confirmed upsert lands the row directly in the terminal confirmed state;
                // the store has no public markConfirmed, so this is how a confirmed row is seeded.
                $store->upsertPending(self::terminalSeedRow($url, MatchStatus::Confirmed));
            },
        ];
    }

    /**
     * Builds a minimal stored match for the terminal-seed provider, carrying a valid payload shape and
     * the given status.
     *
     * @param string      $url    The source URL.
     * @param MatchStatus $status The status to stamp on the seed row.
     *
     * @return StoredMatch The seed row.
     */
    private static function terminalSeedRow(string $url, MatchStatus $status): StoredMatch
    {
        return new StoredMatch('I1', $url, $status, [
            'personId'       => 'I1',
            'obituaryUrl'    => $url,
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
     * Builds a StoredMatch carrying a minimal valid ClassifiedMatchArray payload with
     * MatchStatus::Pending, mirroring what the ingest pipeline would write.
     *
     * @param string $personId    The candidate identifier.
     * @param string $obituaryUrl The source URL.
     *
     * @return StoredMatch The pending stored match.
     */
    private function pendingMatch(string $personId, string $obituaryUrl): StoredMatch
    {
        return new StoredMatch($personId, $obituaryUrl, MatchStatus::Pending, [
            'personId'       => $personId,
            'obituaryUrl'    => $obituaryUrl,
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
