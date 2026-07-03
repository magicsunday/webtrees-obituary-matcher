<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Support\FamilyNameMatch;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the display-only loose name match that highlights notice relatives against tree family members.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(FamilyNameMatch::class)]
#[UsesClass(Normalizer::class)]
final class FamilyNameMatchTest extends TestCase
{
    /**
     * The loose family-name match cases, each pinning one dimension of the token-overlap heuristic.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function nameCases(): array
    {
        return [
            'identical given + surname'        => ['Karl Mustermann', 'Karl Mustermann', true],
            'token order does not matter'      => ['Karl Mustermann', 'Mustermann Karl', true],
            'extra middle name still overlaps' => ['Karl Heinz Mustermann', 'Karl Mustermann', true],
            'diacritic and digraph fold'       => ['Karl Müller', 'Karl Mueller', true],
            'surname only is not enough'       => ['Mustermann', 'Karl Mustermann', false],
            'same given different surname'     => ['Karl Mustermann', 'Karl Schmidt', false],
            'single token equal (mononym)'     => ['Mustermann', 'Mustermann', true],
            'single token different (mononym)' => ['Mustermann', 'Schmidt', false],
            'completely different'             => ['Karl Mustermann', 'Anna Schmidt', false],
            'empty tree name'                  => ['', 'Karl Mustermann', false],
            'empty notice name'                => ['Karl Mustermann', '', false],
        ];
    }

    /**
     * Verifies the loose match: two names match when their normalised token sets share at least two
     * tokens (given + surname), or when both collapse to the same single token.
     */
    #[Test]
    #[DataProvider('nameCases')]
    public function matchesTreeAndNoticeNamesLoosely(string $treeName, string $noticeName, bool $expected): void
    {
        self::assertSame($expected, FamilyNameMatch::matches($treeName, $noticeName));
    }

    /**
     * matchesAny returns true when any candidate loosely matches, false for an empty candidate list or
     * when none matches.
     */
    #[Test]
    public function matchesAnyReturnsTrueOnTheFirstLooseMatch(): void
    {
        $candidates = ['Anna Schmidt', 'Karl Mustermann', 'Otto Beispiel'];

        self::assertTrue(FamilyNameMatch::matchesAny('Karl Mustermann', $candidates));
        self::assertFalse(FamilyNameMatch::matchesAny('Petra Fremd', $candidates));
        self::assertFalse(FamilyNameMatch::matchesAny('Karl Mustermann', []));
    }
}
