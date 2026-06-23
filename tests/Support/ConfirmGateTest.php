<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\ConfirmDecision;
use MagicSunday\ObituaryMatcher\Support\ConfirmGate;
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the confirm gate, incl. the reason priority over combined failures.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(ConfirmGate::class)]
#[UsesClass(ConfirmDecision::class)]
#[UsesClass(GedcomDateConverter::class)]
final class ConfirmGateTest extends TestCase
{
    /**
     * The full gate matrix, including combinations that exercise the reason priority.
     *
     * @param bool        $hardConflict     Whether the engine flagged a hard conflict.
     * @param bool        $treeHasDeathDate Whether the tree person already has a death date.
     * @param string|null $iso              The extracted ISO death date.
     * @param bool        $allowed          The expected canConfirm.
     * @param string|null $reason           The expected reason key.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('matrix')]
    public function evaluatesTheGateAndPicksTheHighestPriorityReason(
        bool $hardConflict,
        bool $treeHasDeathDate,
        ?string $iso,
        bool $allowed,
        ?string $reason,
    ): void {
        $decision = ConfirmGate::evaluate($hardConflict, $treeHasDeathDate, $iso);

        self::assertSame($allowed, $decision->canConfirm);
        self::assertSame($reason, $decision->reasonKey);
    }

    /**
     * @return array<string, array{bool, bool, string|null, bool, string|null}>
     */
    public static function matrix(): array
    {
        return [
            'allowed'                       => [false, false, '2023-09-04', true, null],
            'hard conflict alone'           => [true, false, '2023-09-04', false, 'hard_conflict'],
            'tree date alone'               => [false, true, '2023-09-04', false, 'tree_already_has_death_date'],
            'no exact date alone (absent)'  => [false, false, null, false, 'no_exact_death_date'],
            'no exact date alone (imprec.)' => [false, false, '2023-09', false, 'no_exact_death_date'],
            // priority: hard conflict wins over everything
            'conflict + tree date + bad' => [true, true, '2023-09', false, 'hard_conflict'],
            // priority: tree date wins over a non-exact date
            'tree date + non-exact' => [false, true, '2023-09', false, 'tree_already_has_death_date'],
        ];
    }
}
