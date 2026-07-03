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
use InvalidArgumentException;
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
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
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
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;
use function symlink;

use const JSON_THROW_ON_ERROR;

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
#[UsesClass(WriteBack::class)]
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
     * findByPerson groups each candidate's rows under a per-person sub-directory: it returns only the
     * requesting candidate's rows, and the row file physically lives at
     * {dir}/{sha256(personId)}/{rowKey}.json — NOT under the old flat
     * {dir}/sha256(personId."\0".rowKey).json scheme. This pins the GH-26 layout that makes
     * findByPerson O(rows-for-this-person) instead of O(whole-store).
     *
     * @return void
     */
    #[Test]
    public function findByPersonScansOnlyThePersonSubDirectory(): void
    {
        $store = new FileMatchStore($this->tmp);

        $urlOne = 'https://example.test/a';
        $store->upsertPending($this->storedMatch('I1', $urlOne, MatchStatus::Pending));
        $store->upsertPending($this->storedMatch('I2', 'https://example.test/b', MatchStatus::Pending));

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows, 'only the requesting candidate\'s rows surface');
        self::assertSame('I1', $rows[0]->personId);

        // The row lives under the per-person sub-directory keyed by sha256(personId).
        $rowKey     = StoredMatchKey::fromUrl($urlOne);
        $subDirPath = $this->tmp . '/' . hash('sha256', 'I1') . '/' . $rowKey . '.json';
        $flatLegacy = $this->tmp . '/' . hash('sha256', 'I1' . "\0" . $rowKey) . '.json';

        self::assertTrue(is_file($subDirPath), 'the row is stored under {dir}/sha256(personId)/{rowKey}.json');
        self::assertFalse(is_file($flatLegacy), 'the old flat folded-filename scheme is no longer used');
    }

    /**
     * findByPerson filters its sub-directory scan by the requested candidate id as defence-in-depth: a
     * VALID stored-match row whose CONTENT personId is I2, physically MISPLACED into I1's sub-directory
     * (a manual-edit / corruption fault), is NOT returned for I1. Only the legit I1 row surfaces (count
     * one, personId I1). Without the personId equality guard the partition would be trusted blindly and
     * both rows would be returned — so the exclusion of the misplaced row is the discriminator.
     *
     * @return void
     */
    #[Test]
    public function findByPersonExcludesAMisplacedRowFromTheWrongSubDirectory(): void
    {
        $store = new FileMatchStore($this->tmp);

        // The legit I1 row lands in I1's own sub-directory.
        $store->upsertPending($this->pendingMatch('I1', 'https://example.test/a'));

        // Physically write a VALID stored-match JSON whose content personId is I2 INTO I1's sub-directory
        // at {dir}/sha256('I1')/{64-hex}.json — the manual-misplacement / corruption fault.
        $misplaced     = $this->pendingMatch('I2', 'https://example.test/b');
        $misplacedPath = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl('https://example.test/b') . '.json';
        file_put_contents($misplacedPath, json_encode($misplaced->toArray(), JSON_THROW_ON_ERROR));

        $rows = $store->findByPerson('I1');
        self::assertCount(1, $rows, 'the misplaced I2 row in I1\'s sub-directory is filtered out');
        self::assertSame('I1', $rows[0]->personId);
    }

    /**
     * allPending recurses one level into the per-person sub-directories: pending rows seeded for two
     * different candidates, each landing in its own sub-directory, both surface — proving the recursive
     * scan walks across persons.
     *
     * @return void
     */
    #[Test]
    public function allPendingRecursesAcrossPersonSubDirectories(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));
        $store->upsertPending($this->storedMatch('I2', 'https://example.test/b', MatchStatus::Pending));

        // The rows physically live under per-person sub-directories with NO flat *.json at the root,
        // so allPending must recurse one level to find them (the old flat scheme would not pass this).
        self::assertTrue(is_dir($this->tmp . '/' . hash('sha256', 'I1')), 'I1 has its own sub-directory');
        self::assertTrue(is_dir($this->tmp . '/' . hash('sha256', 'I2')), 'I2 has its own sub-directory');
        self::assertSame([], glob($this->tmp . '/*.json'), 'no row lives flat at the store root');

        $pending = $store->allPending();
        self::assertCount(2, $pending, 'allPending walks every per-person sub-directory');

        $personIds = [$pending[0]->personId, $pending[1]->personId];
        self::assertContains('I1', $personIds);
        self::assertContains('I2', $personIds);
    }

    /**
     * A corrupt *.json planted in one candidate's sub-directory does not hide a valid row in another
     * candidate's sub-directory: the recursive allPending scan skips the poison row and still surfaces
     * the well-formed one, and findByPerson of the poisoned candidate tolerates the corrupt row too.
     *
     * @return void
     */
    #[Test]
    public function aCorruptRowInOneSubDirectoryDoesNotHideValidRowsAcrossSubDirectories(): void
    {
        $store = new FileMatchStore($this->tmp);

        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));

        // Plant a corrupt row inside I2's sub-directory, alongside I1's valid row in its own sub-dir.
        $corruptDir = $this->tmp . '/' . hash('sha256', 'I2');
        mkdir($corruptDir, 0o700, true);
        file_put_contents($corruptDir . '/corrupt.json', '{"not":"a stored match"}');

        $pending = $store->allPending();
        self::assertCount(1, $pending, 'the poison row in I2 is skipped, I1 survives');
        self::assertSame('I1', $pending[0]->personId);

        // findByPerson of the poisoned candidate's sub-dir tolerates the corrupt row (returns []).
        self::assertCount(0, $store->findByPerson('I2'));
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
     * all returns every stored row regardless of status: a pending, a rejected and an uncertain row
     * seeded across three candidates all surface, each carrying its persisted status. allPending
     * (Pending only) is the discriminator — all must not filter by status.
     *
     * @return void
     */
    #[Test]
    public function allReturnsEveryRowAcrossAllStatuses(): void
    {
        $store = new FileMatchStore($this->tmp);
        $store->upsertPending($this->pendingMatch('I1', 'https://obituary.example/a'));
        $store->upsertPending($this->pendingMatch('I2', 'https://obituary.example/b'));
        $store->markRejected('I2', 'https://obituary.example/b', null);
        $store->upsertPending($this->pendingMatch('I3', 'https://obituary.example/c'));
        $store->markUncertain('I3', 'https://obituary.example/c', null);

        $all = $store->all();

        self::assertCount(3, $all);

        $byPerson = [];

        foreach ($all as $row) {
            $byPerson[$row->personId] = $row->status;
        }

        self::assertSame(MatchStatus::Pending, $byPerson['I1']);
        self::assertSame(MatchStatus::Rejected, $byPerson['I2']);
        self::assertSame(MatchStatus::Uncertain, $byPerson['I3']);
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
     * An in-flight atomic temp file ("<rowKey>.json.tmp.<uniqid>") sitting INSIDE a candidate's
     * per-person sub-directory — the exact transient AtomicFile creates before the rename — is not
     * scanned as a row: scanDir's extension filter excludes it because pathinfo() reports the uniqid
     * as the extension, not "json". The valid row in that same sub-directory still surfaces through
     * both findByPerson (single sub-dir) and allPending (recursive scan), each returning exactly one
     * row. The temp file carries a fully VALID, decodable StoredMatch payload for a DIFFERENT URL, so
     * a regression that stops excluding it would hand it to the JSON decoder, reconstruct it as a
     * genuine second pending row and push the count to two — the exact count is the discriminator
     * (an arbitrary/corrupt payload would be swallowed by the poison-tolerant catch and hide the
     * regression).
     *
     * @return void
     */
    #[Test]
    public function anInFlightTempFileInsideAPersonSubDirectoryIsNotScanned(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://example.test/a';
        $store->upsertPending($this->storedMatch('I1', $url, MatchStatus::Pending));

        // The transient shape AtomicFile writes into the person sub-directory before the rename:
        // {dir}/sha256('I1')/{rowKey}.json.tmp.<uniqid>. Its extension is the uniqid, not "json". It
        // carries a VALID payload for a second URL so it would decode into a real second row if the
        // extension filter ever stopped excluding it (the corrupt-row catch could not mask that).
        $tempRow  = $this->pendingMatch('I1', 'https://example.test/in-flight');
        $tempPath = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json.tmp.deadbeef';
        file_put_contents($tempPath, json_encode($tempRow->toArray(), JSON_THROW_ON_ERROR));

        $found = $store->findByPerson('I1');
        self::assertCount(1, $found, 'the in-flight temp file in the sub-directory is excluded from findByPerson');
        self::assertSame('I1', $found[0]->personId);

        self::assertCount(1, $store->allPending(), 'the in-flight temp file in the sub-directory is excluded from allPending');
    }

    /**
     * A stray non-directory entry at the store root (a leftover flat-scheme "*.json" from before the
     * GH-26 migration, a ".DS_Store", …) is never decoded as a row: allRows recurses ONE level into
     * the per-person sub-directories only, treating the store root as a directory-of-directories. The
     * stray file carries a fully VALID, decodable StoredMatch payload, so it would surface as a genuine
     * second pending row if allRows ever flattened the scan (for example a recursive-glob rewrite that
     * dropped the one-level structure) — the exact count of one is the discriminator. The valid pending
     * row under its proper sub-directory still surfaces.
     *
     * @return void
     */
    #[Test]
    public function aStrayNonDirectoryFileAtTheStoreRootIsIgnored(): void
    {
        $store = new FileMatchStore($this->tmp);
        $store->upsertPending($this->storedMatch('I1', 'https://example.test/a', MatchStatus::Pending));

        // A stray file directly at the store root (not inside a per-person sub-directory). It carries a
        // VALID payload so a flattening regression would reconstruct it into a real second pending row;
        // the one-level recursion must leave it untouched at the root.
        $strayRow = $this->pendingMatch('I9', 'https://example.test/stray');
        file_put_contents($this->tmp . '/stray.json', json_encode($strayRow->toArray(), JSON_THROW_ON_ERROR));

        $pending = $store->allPending();
        self::assertCount(1, $pending, 'the stray root-level file is not decoded, the valid sub-directory row survives');
        self::assertSame('I1', $pending[0]->personId);
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
        $url   = 'https://example.test/a';
        $match = $this->storedMatch('I1', $url, MatchStatus::Pending);
        $store->upsertPending($match);

        // Overwrite the stored row with a malformed payload so the next read of the SAME key fails. The
        // row lives under the per-person sub-directory {dir}/sha256(personId)/{rowKey}.json.
        $path = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json';
        file_put_contents($path, '{"broken":true}');

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

        $path = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json';
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
     * findOne rejects a malformed row key at the path sink rather than building a path from it: a
     * traversal-shaped key, a too-short key, an uppercase-hex key (the guard is lowercase-only) and a
     * trailing-newline key (which a non-"/D"-anchored "$" would let slip past) all throw
     * InvalidArgumentException. Without the guard, findOne would build a path from the raw key and
     * return null (a silent miss / traversal) rather than throw — so the throw is the discriminator.
     *
     * @param string $badKey A row key that is not a 64-character lowercase hex string.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('malformedRowKeyProvider')]
    public function findOneRejectsAMalformedRowKey(string $badKey): void
    {
        $store = new FileMatchStore($this->tmp);

        $this->expectException(InvalidArgumentException::class);

        $store->findOne('I1', $badKey);
    }

    /**
     * Provides row keys that must be rejected by the path-sink guard: each is NOT a 64-character
     * lowercase hex string.
     *
     * @return iterable<string, array{string}> The malformed row keys.
     */
    public static function malformedRowKeyProvider(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'too short' => ['abc'];
        yield 'uppercase hex (guard is lowercase-only)' => [str_repeat('A', 64)];
        yield 'valid hex with a trailing newline' => [str_repeat('a', 64) . "\n"];
    }

    /**
     * findOne accepts a valid 64-character lowercase hex row key (the StoredMatchKey shape) and does
     * NOT throw: it resolves to the row the upsert wrote. This pins the happy path of the path-sink
     * guard alongside the malformed-key rejections.
     *
     * @return void
     */
    #[Test]
    public function findOneAcceptsAValidHexRowKey(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $found = $store->findOne('I1', StoredMatchKey::fromUrl($url));

        self::assertInstanceOf(StoredMatch::class, $found);
        self::assertSame('I1', $found->personId);
    }

    /**
     * A symlinked directory planted at the store root is skipped by the recursive worklist scan: the
     * symlink target is a real directory OUTSIDE the store holding a fully VALID pending row, so it
     * WOULD surface through allPending if the scan followed the link. allRows now guards "!isDir() ||
     * isLink()", so the row under the symlink target never surfaces. The valid in-store row still does
     * — the exact count is the discriminator (without the isLink() guard the count would be two).
     *
     * @return void
     */
    #[Test]
    public function allPendingSkipsASymlinkedDirectoryAtTheStoreRoot(): void
    {
        $store = new FileMatchStore($this->tmp);
        $store->upsertPending($this->pendingMatch('I1', 'https://trauer.example/a'));

        // A real directory OUTSIDE the store root, holding a valid pending row, then symlinked INTO the
        // store root. If allRows followed the link, isDir() (true through the link) would let it scan
        // the target and surface the row — the !isLink() guard must skip it.
        $outsideDir = $this->tmp . '-outside';
        mkdir($outsideDir, 0o700, true);
        $hiddenRow = $this->pendingMatch('I9', 'https://trauer.example/hidden');
        file_put_contents(
            $outsideDir . '/' . StoredMatchKey::fromUrl('https://trauer.example/hidden') . '.json',
            json_encode($hiddenRow->toArray(), JSON_THROW_ON_ERROR),
        );

        // Wrap symlink() in the scoped-error-handler idiom (the AtomicFile W4 pattern): a restricted
        // platform makes symlink() raise an E_WARNING that PHPUnit converts to a thrown exception BEFORE
        // the "!== true" check, so the markTestSkipped fallback would otherwise be unreachable.
        set_error_handler(static fn (): bool => true);

        try {
            $symlinkCreated = symlink($outsideDir, $this->tmp . '/evil');
        } finally {
            restore_error_handler();
        }

        if ($symlinkCreated !== true) {
            self::markTestSkipped('symlink() is unavailable on this platform.');
        }

        $pending = $store->allPending();
        self::assertCount(1, $pending, 'the symlinked directory at the store root is skipped');
        self::assertSame('I1', $pending[0]->personId);
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

        $path   = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json';
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

        $path   = $this->tmp . '/' . hash('sha256', 'I1') . '/' . StoredMatchKey::fromUrl($url) . '.json';
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
            'personId'        => 'I1',
            'obituaryUrl'     => $url,
            'score'           => 80,
            'hardConflict'    => false,
            'signals'         => [],
            'extractedFacts'  => [],
            'noticeRelatives' => [],
            'classification'  => 'probable',
            'ambiguous'       => false,
            'runnerUp'        => null,
            'review'          => null,
        ]);
    }

    /**
     * markConfirmed moves a pending row to confirmed, persists the write-back, and returns true.
     *
     * @return void
     */
    #[Test]
    public function markConfirmedTransitionsAndPersistsWriteBack(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $transitioned = $store->markConfirmed('I1', $url, new WriteBack('deat-1', 'S5', true));

        self::assertTrue($transitioned);
        $row = $store->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $row);
        self::assertSame(MatchStatus::Confirmed, $row->status);
        self::assertSame(['deatFactId' => 'deat-1', 'buriFactId' => null, 'cremFactId' => null, 'sourceXref' => 'S5', 'sourceCreated' => true, 'citationIds' => []], $row->writeBack);
    }

    /**
     * markConfirmed on uncertain transitions too.
     *
     * @return void
     */
    #[Test]
    public function markConfirmedTransitionsFromUncertain(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));
        $store->markUncertain('I1', $url, null);

        self::assertTrue($store->markConfirmed('I1', $url, new WriteBack('d', 'S1', false)));

        $row = $store->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $row);
        self::assertSame(MatchStatus::Confirmed, $row->status);
        // The from-uncertain branch must also persist the supplied write-back, not only flip the status.
        self::assertIsArray($row->writeBack);
        self::assertSame('d', $row->writeBack['deatFactId']);
        self::assertSame('S1', $row->writeBack['sourceXref']);
    }

    /**
     * markConfirmed on an already-confirmed row is a no-op, returns false, and never rewrites the
     * existing write-back (content + mtime unchanged) even with a different WriteBack.
     *
     * @return void
     */
    #[Test]
    public function markConfirmedIsIdempotentNoOpWithoutRewrite(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));
        $store->markConfirmed('I1', $url, new WriteBack('first', 'S1', true));

        // Locate the single stored row file WITHOUT re-implementing the store's path scheme in the test
        // (the layout is one per-person sub-dir deep: {tmp}/{hash}/{rowKey}.json). Exactly one row seeded.
        $files = glob($this->tmp . '/*/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $path   = $files[0];
        $before = hash_file('sha256', $path);
        clearstatcache(true, $path);
        $mtime = filemtime($path);

        $second = $store->markConfirmed('I1', $url, new WriteBack('second', 'S9', false));

        clearstatcache(true, $path);
        self::assertFalse($second, 'a second confirm must report no transition');
        self::assertSame($before, hash_file('sha256', $path), 'the confirmed write-back must not be overwritten');
        self::assertSame($mtime, filemtime($path));

        $row = $store->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $row);
        self::assertIsArray($row->writeBack);
        self::assertSame('first', $row->writeBack['deatFactId']);
    }

    /**
     * markConfirmed on a rejected row throws.
     *
     * @return void
     */
    #[Test]
    public function markConfirmedThrowsOnRejected(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));
        $store->markRejected('I1', $url, null);

        $this->expectException(TerminalMatchTransitionException::class);

        $store->markConfirmed('I1', $url, new WriteBack('d', 'S1', false));
    }

    /**
     * markConfirmed on a vanished row is a no-op returning false (no synthetic row).
     *
     * @return void
     */
    #[Test]
    public function markConfirmedOnMissingRowIsANoOp(): void
    {
        $store = new FileMatchStore($this->tmp);

        self::assertFalse($store->markConfirmed('I1', 'https://trauer.example/missing', new WriteBack('d', 'S1', false)));

        // The "no synthetic row" half of the contract: the call must not have written a confirmed row.
        self::assertNull($store->findOne('I1', StoredMatchKey::fromUrl('https://trauer.example/missing')));
    }

    /**
     * revert turns a Confirmed row back to Pending so it can be re-reviewed: the scored match is
     * preserved, while the reviewer reason and the write-back record are both cleared.
     *
     * @return void
     */
    #[Test]
    public function revertTurnsAConfirmedRowBackToPendingPreservingTheMatch(): void
    {
        $store      = new FileMatchStore($this->tmp);
        $url        = 'https://trauer.example/a';
        $seeded     = $this->pendingMatch('I1', $url);
        $scoredHash = json_encode($seeded->match, JSON_THROW_ON_ERROR);
        $store->upsertPending($seeded);
        $store->markUncertain('I1', $url, 'looks plausible');
        $store->markConfirmed('I1', $url, new WriteBack('deat-id', 'S1', true, 'buri-id'));

        $store->revert('I1', $url);

        $row = $store->findOne('I1', StoredMatchKey::fromUrl($url));
        self::assertInstanceOf(StoredMatch::class, $row);
        self::assertSame(MatchStatus::Pending, $row->status);
        self::assertNull($row->writeBack);
        self::assertNull($row->reason);
        // The scored suggestion survives the round-trip unchanged (the reviewer reason and write-back
        // were cleared, but the engine's scored payload is preserved verbatim).
        self::assertSame($scoredHash, json_encode($row->match, JSON_THROW_ON_ERROR));
    }

    /**
     * revert refuses a row that is not Confirmed (here a Pending row): it throws
     * TerminalMatchTransitionException rather than silently rewriting a non-confirmed row.
     *
     * @return void
     */
    #[Test]
    public function revertRefusesARowThatIsNotConfirmed(): void
    {
        $store = new FileMatchStore($this->tmp);
        $url   = 'https://trauer.example/a';
        $store->upsertPending($this->pendingMatch('I1', $url));

        $this->expectException(TerminalMatchTransitionException::class);

        $store->revert('I1', $url);
    }

    /**
     * revert refuses a row that no longer exists: it throws TerminalMatchTransitionException rather
     * than synthesising a pending row out of nothing.
     *
     * @return void
     */
    #[Test]
    public function revertRefusesAMissingRow(): void
    {
        $store = new FileMatchStore($this->tmp);

        $this->expectException(TerminalMatchTransitionException::class);

        $store->revert('I1', 'https://trauer.example/missing');
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
            'personId'        => $personId,
            'obituaryUrl'     => $obituaryUrl,
            'score'           => 80,
            'hardConflict'    => false,
            'signals'         => [],
            'extractedFacts'  => [],
            'noticeRelatives' => [],
            'classification'  => 'probable',
            'ambiguous'       => false,
            'runnerUp'        => null,
            'review'          => null,
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
