<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Domain\DateRange;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use MagicSunday\ObituaryMatcher\Support\RufnameParser;
use MagicSunday\ObituaryMatcher\Webtrees\PersonCandidateAdapter;
use MagicSunday\ObituaryMatcher\Webtrees\WebtreesDateMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function file_get_contents;

/**
 * Maps a real webtrees {@see Individual} from an imported tree to the engine's pure
 * {@see \MagicSunday\ObituaryMatcher\Domain\PersonCandidate} and proves both the
 * happy-path field mapping (a married woman with a birth name, a married name, a call
 * name, a spouse and a child) and the privacy gate (a confidential individual maps to
 * null; confidential relatives are omitted from the family arrays).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(PersonCandidateAdapter::class)]
#[UsesClass(WebtreesDateMapper::class)]
#[UsesClass(RufnameParser::class)]
#[UsesClass(DateRange::class)]
#[UsesClass(Gender::class)]
#[UsesClass(Place::class)]
#[UsesClass(RelatedPerson::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PersonName::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\PersonCandidate::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DateValue::class)]
#[UsesClass(\MagicSunday\ObituaryMatcher\Domain\DatePrecision::class)]
final class PersonCandidateAdapterTest extends IntegrationTestCase
{
    /**
     * Import the adapter fixture and configure the tree's privacy preferences so dead
     * people are visible to visitors while confidential records stay hidden — the same
     * production defaults the live module runs under.
     *
     * @return Tree The imported fixture tree.
     */
    private function adapterTree(): Tree
    {
        $gedcom = file_get_contents(__DIR__ . '/../fixtures/adapter.ged');

        self::assertIsString($gedcom);

        $tree = $this->importFixtureTree($gedcom);

        // Production trees ship these defaults; the bare test tree leaves them empty,
        // which would hide even dead people from a visitor and mask the privacy gate.
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);
        $tree->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_USER);

        return $tree;
    }

    /**
     * Resolve an individual that must exist within the fixture tree.
     *
     * @param string $xref The XREF to resolve.
     * @param Tree   $tree The fixture tree.
     *
     * @return Individual The resolved individual.
     */
    private function requireIndividual(string $xref, Tree $tree): Individual
    {
        $individual = $this->individual($xref, $tree);

        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }

    #[Test]
    public function mapsMarriedWomanWithFullNameAndFamilyGraph(): void
    {
        $tree       = $this->adapterTree();
        $individual = $this->requireIndividual('I1', $tree);

        $candidate = PersonCandidateAdapter::fromIndividual($individual);

        self::assertNotNull($candidate);
        self::assertSame('I1', $candidate->id);
        self::assertSame(Gender::Female, $candidate->gender);

        // Given names decomposed from the primary NAME's GIVN ("Maria Anna").
        self::assertSame(['Maria', 'Anna'], $candidate->name->givenNames);

        // Birth surname from the primary NAME's SURN.
        self::assertSame('Schmidt', $candidate->name->birthSurname);
        self::assertSame('Schmidt', $candidate->name->surname);

        // Married surname from the _MARNM name row.
        self::assertSame(['Mueller'], $candidate->name->marriedSurnames);

        // Call name from the _RUFNAME tag.
        self::assertSame('Anna', $candidate->name->callName);

        // Birth is a known range; death is present in this fixture.
        self::assertTrue($candidate->birth->isKnown());
        self::assertNotNull($candidate->birthPlace);
        self::assertSame('Bonn, Germany', $candidate->birthPlace->name);

        // Places carry the BIRT and RESI place names.
        $placeNames = array_map(static fn (Place $place): string => $place->name, $candidate->places);
        self::assertContains('Bonn, Germany', $placeNames);
        self::assertContains('Cologne, Germany', $placeNames);

        // Family graph: one spouse (Hans), one child (Klara), both with plain names.
        self::assertCount(1, $candidate->spouses);
        self::assertCount(1, $candidate->children);

        $spouse = $candidate->spouses[0];
        self::assertSame('I2', $spouse->id);
        self::assertSame(['Hans'], $spouse->name->givenNames);
        self::assertSame('Mueller', $spouse->name->surname);
        self::assertSame(Gender::Male, $spouse->gender);

        $child = $candidate->children[0];
        self::assertSame('I3', $child->id);
        self::assertSame(['Klara'], $child->name->givenNames);
        self::assertSame('Mueller', $child->name->surname);
    }

    #[Test]
    public function confidentialIndividualMapsToNullForVisitor(): void
    {
        $tree       = $this->adapterTree();
        $individual = $this->requireIndividual('I4', $tree);

        // As an admin the confidential record is fully visible — guard against a
        // vacuous test by proving the gate only engages once we drop to a visitor.
        self::assertTrue($individual->canShow());

        Auth::logout();

        // Re-resolve so the privacy cache is computed against the visitor context.
        $visitorView = $this->requireIndividual('I4', $tree);
        self::assertFalse($visitorView->canShow());

        self::assertNull(PersonCandidateAdapter::fromIndividual($visitorView));
    }

    #[Test]
    public function confidentialRelativesAreOmittedForVisitor(): void
    {
        $tree = $this->adapterTree();

        // Prove the relatives are confidential: hidden from a visitor, shown to admin.
        $confidentialSpouse = $this->requireIndividual('I6', $tree);
        $confidentialChild  = $this->requireIndividual('I7', $tree);
        self::assertTrue($confidentialSpouse->canShow());
        self::assertTrue($confidentialChild->canShow());

        // Positive control: as an admin the head's family graph DOES surface the
        // confidential spouse and child, so the visitor-side emptiness below can only
        // be the privacy gate firing, not a never-traversed family.
        $adminCandidate = PersonCandidateAdapter::fromIndividual($this->requireIndividual('I5', $tree));

        self::assertNotNull($adminCandidate);
        self::assertCount(1, $adminCandidate->spouses);
        self::assertCount(1, $adminCandidate->children);
        self::assertSame('I6', $adminCandidate->spouses[0]->id);
        self::assertSame('I7', $adminCandidate->children[0]->id);

        Auth::logout();

        $head = $this->requireIndividual('I5', $tree);

        // The head of the family must itself be visible to a visitor (dead, not
        // confidential), otherwise the omission assertion would be vacuous.
        self::assertTrue($head->canShow());
        self::assertFalse($this->requireIndividual('I6', $tree)->canShow());
        self::assertFalse($this->requireIndividual('I7', $tree)->canShow());

        $candidate = PersonCandidateAdapter::fromIndividual($head);

        self::assertNotNull($candidate);
        self::assertSame([], $candidate->spouses);
        self::assertSame([], $candidate->children);
    }
}
