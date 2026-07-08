<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Support;

use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use MagicSunday\ObituaryMatcher\Support\SearchSignatureFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the search-signature derivation: the signature fingerprints ONLY the person's intrinsic
 * search-defining state, so an equivalent person yields the same signature and a change to the searched
 * data yields a different one — the property §5.2d relies on to key negative memory stably.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(SearchSignatureFactory::class)]
#[UsesClass(Normalizer::class)]
final class SearchSignatureFactoryTest extends TestCase
{
    /**
     * The same searchable state yields the same signature, regardless of the person id (the id is not
     * part of what is searched).
     *
     * @return void
     */
    #[Test]
    public function sameSearchStateYieldsTheSameSignature(): void
    {
        $a = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Musterstadt']));
        $b = SearchSignatureFactory::fromCandidate($this->candidate('I2', ['Otto'], 'Vorbild', 1900, ['Musterstadt']));

        self::assertSame($a->hash, $b->hash);
    }

    /**
     * A diacritic/spelling variant of the surname collapses to the same signature, because the name
     * tokens run through the same normalisation the matcher searches with (Müller/Mueller/Muller).
     *
     * @return void
     */
    #[Test]
    public function diacriticSurnameVariantCollapsesToTheSameSignature(): void
    {
        $a = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Anna'], 'Müller', 1920, []));
        $b = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Anna'], 'Mueller', 1920, []));

        self::assertSame($a->hash, $b->hash);
    }

    /**
     * The order the tree lists a person's places in does not change the signature — the search is over
     * the SET of places, so a reordered residence list is the same search.
     *
     * @return void
     */
    #[Test]
    public function placeOrderDoesNotChangeTheSignature(): void
    {
        $a = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Musterstadt', 'Beispielstadt']));
        $b = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Beispielstadt', 'Musterstadt']));

        self::assertSame($a->hash, $b->hash);
    }

    /**
     * A change to any searched field — surname, birth year or places — yields a different signature, so
     * an edited person becomes eligible for a fresh search.
     *
     * @return void
     */
    #[Test]
    public function changingASearchedFieldChangesTheSignature(): void
    {
        $base = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Musterstadt'], 'Geboren', 'Musterregion'));

        // One variation per signature field, so every field that feeds the hash is proven to change it.
        $given        = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Emil'], 'Vorbild', 1900, ['Musterstadt'], 'Geboren', 'Musterregion'));
        $surname      = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Anders', 1900, ['Musterstadt'], 'Geboren', 'Musterregion'));
        $birthSurname = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Musterstadt'], 'Andersgeboren', 'Musterregion'));
        $year         = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1901, ['Musterstadt'], 'Geboren', 'Musterregion'));
        $place        = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Andernorts'], 'Geboren', 'Musterregion'));
        $region       = SearchSignatureFactory::fromCandidate($this->candidate('I1', ['Otto'], 'Vorbild', 1900, ['Musterstadt'], 'Geboren', 'Andernregion'));

        self::assertNotSame($base->hash, $given->hash);
        self::assertNotSame($base->hash, $surname->hash);
        self::assertNotSame($base->hash, $birthSurname->hash);
        self::assertNotSame($base->hash, $year->hash);
        self::assertNotSame($base->hash, $place->hash);
        self::assertNotSame($base->hash, $region->hash);
    }

    /**
     * Builds a candidate with the given searchable state.
     *
     * @param string       $xref         The person id.
     * @param list<string> $given        The given names.
     * @param string       $surname      The surname.
     * @param int          $birthYear    The birth year.
     * @param list<string> $placeNames   The residence place names.
     * @param string|null  $birthSurname The birth (maiden) surname, or null.
     * @param string|null  $region       The birth-place region, or null.
     *
     * @return PersonCandidate The candidate.
     */
    private function candidate(
        string $xref,
        array $given,
        string $surname,
        int $birthYear,
        array $placeNames,
        ?string $birthSurname = null,
        ?string $region = null,
    ): PersonCandidate {
        $places = [];

        foreach ($placeNames as $name) {
            $places[] = new Place($name);
        }

        $birthPlace = ($region !== null)
            ? new Place('Geburtsort', null, $region)
            : null;

        return new PersonCandidate(
            $xref,
            Gender::Unknown,
            new PersonName($given, null, $surname, $birthSurname),
            DateRange::year($birthYear),
            $birthPlace,
            $places,
            DateRange::unknown(),
        );
    }
}
