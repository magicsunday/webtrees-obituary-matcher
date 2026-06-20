<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Individual;
use MagicSunday\ObituaryMatcher\Domain\Gender;
use MagicSunday\ObituaryMatcher\Domain\PersonCandidate;
use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\Place;
use MagicSunday\ObituaryMatcher\Domain\RelatedPerson;
use MagicSunday\ObituaryMatcher\Support\RufnameParser;

use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Maps a webtrees {@see Individual} to the engine's pure {@see PersonCandidate}, gated by
 * privacy: a record the current user may not see maps to null, and every relative is
 * surfaced only when the current user may see it.
 *
 * This adapter, together with {@see WebtreesDateMapper}, is the only place in the package
 * allowed to depend on the `Fisharebest\Webtrees` namespace; the scoring engine itself
 * stays free of any webtrees coupling so it can be unit-tested without a tree.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class PersonCandidateAdapter
{
    /**
     * Static-only utility; never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Maps a webtrees individual to a {@see PersonCandidate}, or null when the current
     * user may not see the individual.
     *
     * @param Individual $i The webtrees individual to convert.
     *
     * @return PersonCandidate|null The mapped candidate, or null when privacy hides it.
     */
    public static function fromIndividual(Individual $i): ?PersonCandidate
    {
        if (!$i->canShow()) {
            return null;
        }

        $places = self::places($i);

        return new PersonCandidate(
            $i->xref(),
            self::gender($i->sex()),
            self::name($i),
            WebtreesDateMapper::toRange($i->getBirthDate()),
            $places[0] ?? null,
            $places,
            WebtreesDateMapper::toRange($i->getDeathDate()),
            self::spouses($i),
            self::children($i),
        );
    }

    /**
     * Decomposes the individual's name rows into the engine {@see PersonName}: the primary
     * `NAME` row supplies the given names and the birth surname, additional `NAME` rows
     * become aliases, and `_MARNM` rows become married surnames.
     *
     * @param Individual $i The individual whose names to map.
     *
     * @return PersonName The decomposed name.
     */
    private static function name(Individual $i): PersonName
    {
        $names   = $i->getAllNames();
        $primary = $names[$i->getPrimaryName()];

        $givenNames   = self::splitGivenNames(self::nameField($primary, 'givn'));
        $birthSurname = self::nameField($primary, 'surn');
        $surname      = $birthSurname;

        $marriedSurnames = [];
        $aliases         = [];

        foreach ($names as $index => $name) {
            if ($index === $i->getPrimaryName()) {
                continue;
            }

            $type = self::nameField($name, 'type');
            $surn = self::nameField($name, 'surn');

            if ($type === '_MARNM') {
                if ($surn !== '') {
                    $marriedSurnames[] = $surn;
                }

                continue;
            }

            if ($type === 'NAME') {
                $given = self::nameField($name, 'givn');

                if ($given !== '') {
                    $aliases[] = $given;
                }
            }
        }

        return new PersonName(
            $givenNames,
            RufnameParser::parse($i->gedcom()),
            $surname,
            $birthSurname === '' ? null : $birthSurname,
            $marriedSurnames,
            $aliases,
        );
    }

    /**
     * Reads a string field from a webtrees name row, collapsing a missing key to an empty
     * string so callers never have to guard the loosely-populated shape themselves.
     *
     * @param array<string, string> $name The name row from {@see Individual::getAllNames()}.
     * @param string                $key  The field to read.
     *
     * @return string The trimmed field value, or an empty string when absent.
     */
    private static function nameField(array $name, string $key): string
    {
        return trim($name[$key] ?? '');
    }

    /**
     * Splits a GEDCOM `GIVN` value into an ordered list of given names.
     *
     * @param string $givn The raw given-name field (space-separated).
     *
     * @return list<string> The individual given names in order.
     */
    private static function splitGivenNames(string $givn): array
    {
        $parts = preg_split('/\s+/', $givn, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        return $parts;
    }

    /**
     * Maps the GEDCOM sex code to a {@see Gender}.
     *
     * @param string $sex The webtrees sex code ('M', 'F' or 'U').
     *
     * @return Gender The mapped gender.
     */
    private static function gender(string $sex): Gender
    {
        return match ($sex) {
            'M'     => Gender::Male,
            'F'     => Gender::Female,
            default => Gender::Unknown,
        };
    }

    /**
     * Collects the places attached to the individual's `BIRT` and `RESI` facts.
     *
     * @param Individual $i The individual whose places to collect.
     *
     * @return list<Place> The mapped places in fact order (birth first).
     */
    private static function places(Individual $i): array
    {
        $places = [];

        foreach ($i->facts(['BIRT', 'RESI']) as $fact) {
            $name = trim($fact->place()->gedcomName());

            if ($name !== '') {
                $places[] = new Place($name);
            }
        }

        return $places;
    }

    /**
     * Builds the visible spouses from the individual's spouse families, excluding the
     * individual itself and any relative the current user may not see.
     *
     * @param Individual $i The individual whose spouses to collect.
     *
     * @return list<RelatedPerson> The visible spouses.
     */
    private static function spouses(Individual $i): array
    {
        $spouses = [];

        foreach ($i->spouseFamilies() as $family) {
            foreach ($family->spouses() as $spouse) {
                if ($spouse->xref() === $i->xref()) {
                    continue;
                }

                if (!$spouse->canShow()) {
                    continue;
                }

                $spouses[] = self::relatedPerson($spouse);
            }
        }

        return $spouses;
    }

    /**
     * Builds the visible children from the individual's spouse families, excluding any
     * relative the current user may not see.
     *
     * @param Individual $i The individual whose children to collect.
     *
     * @return list<RelatedPerson> The visible children.
     */
    private static function children(Individual $i): array
    {
        $children = [];

        foreach ($i->spouseFamilies() as $family) {
            foreach ($family->children() as $child) {
                if (!$child->canShow()) {
                    continue;
                }

                $children[] = self::relatedPerson($child);
            }
        }

        return $children;
    }

    /**
     * Maps a visible relative to a {@see RelatedPerson} with a plain-text name decoded
     * from the HTML webtrees emits.
     *
     * @param Individual $relative The relative to map.
     *
     * @return RelatedPerson The mapped relative.
     */
    private static function relatedPerson(Individual $relative): RelatedPerson
    {
        return new RelatedPerson(
            $relative->xref(),
            self::name($relative),
            self::gender($relative->sex()),
        );
    }
}
