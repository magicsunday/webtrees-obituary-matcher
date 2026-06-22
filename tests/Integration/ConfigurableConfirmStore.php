<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use MagicSunday\ObituaryMatcher\Matching\MatchStore;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use Throwable;

/**
 * A {@see MatchStore} test double that delegates every read/write to a wrapped store except
 * {@see markConfirmed()}, which it drives from an injected outcome: a fixed boolean return, or a
 * thrown {@see Throwable}. It lets {@see ReviewScreenHandlerTest} exercise the confirm handler's
 * Block-B paths (markConfirmed returns false / throws after a successful write) deterministically,
 * without a real concurrent writer, while the resolveRow/findOne lookup still reads the real seeded
 * row from the wrapped temp-directory store. It is a named class (rather than an inline anonymous
 * one) so static analysis fully types the seams at `level: max`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ConfigurableConfirmStore implements MatchStore
{
    /**
     * Constructor.
     *
     * @param MatchStore     $delegate         The wrapped store every method but markConfirmed delegates to.
     * @param bool           $confirmedResult  The boolean markConfirmed returns when it does not throw.
     * @param Throwable|null $confirmedFailure The exception markConfirmed throws, or null to return the boolean.
     */
    public function __construct(
        private readonly MatchStore $delegate,
        private readonly bool $confirmedResult,
        private readonly ?Throwable $confirmedFailure = null,
    ) {
    }

    /**
     * Drives the confirm transition from the injected outcome: throws the configured failure, else
     * returns the configured boolean.
     *
     * @param string    $personId    The candidate identifier.
     * @param string    $obituaryUrl The source URL.
     * @param WriteBack $writeBack   The write-back IDs.
     *
     * @return bool The configured boolean result.
     *
     * @throws Throwable The configured failure, when one was injected.
     */
    public function markConfirmed(string $personId, string $obituaryUrl, WriteBack $writeBack): bool
    {
        if ($this->confirmedFailure instanceof Throwable) {
            throw $this->confirmedFailure;
        }

        return $this->confirmedResult;
    }

    /**
     * Delegates to the wrapped store.
     *
     * @param StoredMatch $match The suggestion to store.
     *
     * @return bool Whether the row was written.
     */
    public function upsertPending(StoredMatch $match): bool
    {
        return $this->delegate->upsertPending($match);
    }

    /**
     * Delegates to the wrapped store.
     *
     * @param string $personId The candidate identifier.
     *
     * @return list<StoredMatch> The stored rows for the person.
     */
    public function findByPerson(string $personId): array
    {
        return $this->delegate->findByPerson($personId);
    }

    /**
     * Delegates to the wrapped store.
     *
     * @return list<StoredMatch> The pending rows.
     */
    public function allPending(): array
    {
        return $this->delegate->allPending();
    }

    /**
     * Delegates to the wrapped store.
     *
     * @param string $personId The candidate identifier.
     * @param string $rowKey   The canonical row key.
     *
     * @return StoredMatch|null The resolved row, or null when absent.
     */
    public function findOne(string $personId, string $rowKey): ?StoredMatch
    {
        return $this->delegate->findOne($personId, $rowKey);
    }

    /**
     * Delegates to the wrapped store.
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source URL.
     * @param string|null $reason      The rejection reason.
     *
     * @return void
     */
    public function markRejected(string $personId, string $obituaryUrl, ?string $reason): void
    {
        $this->delegate->markRejected($personId, $obituaryUrl, $reason);
    }

    /**
     * Delegates to the wrapped store.
     *
     * @param string      $personId    The candidate identifier.
     * @param string      $obituaryUrl The source URL.
     * @param string|null $reason      The reviewer note.
     *
     * @return void
     */
    public function markUncertain(string $personId, string $obituaryUrl, ?string $reason): void
    {
        $this->delegate->markUncertain($personId, $obituaryUrl, $reason);
    }
}
