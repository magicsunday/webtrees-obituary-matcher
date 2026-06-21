<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Scoring;

use MagicSunday\ObituaryMatcher\Domain\PersonName;
use MagicSunday\ObituaryMatcher\Domain\ScoreConfig;
use MagicSunday\ObituaryMatcher\Domain\SignalScore;
use MagicSunday\ObituaryMatcher\Scoring\NameScorer;
use MagicSunday\ObituaryMatcher\Support\ColognePhonetic;
use MagicSunday\ObituaryMatcher\Support\GivenNameVariants;
use MagicSunday\ObituaryMatcher\Support\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the name scorer that weighs given names, surname roles and phonetic matches.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
#[CoversClass(NameScorer::class)]
#[UsesClass(ColognePhonetic::class)]
#[UsesClass(GivenNameVariants::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(ScoreConfig::class)]
#[UsesClass(SignalScore::class)]
final class NameScorerTest extends TestCase
{
    /**
     * Creates a name scorer wired with the Cologne phonetic encoder and default configuration.
     *
     * @return NameScorer
     */
    private function scorer(): NameScorer
    {
        return new NameScorer(new ColognePhonetic(), new ScoreConfig());
    }

    /**
     * A matching married name plus birth surname scores high.
     */
    #[Test]
    public function marriedNamePlusBornNameScoresHigh(): void
    {
        // Candidate: Elise Mueller, married Schmidt. Notice: Elisabeth Schmidt geb. Mueller.
        $candidate = new PersonName(['Elise'], null, 'Mueller', 'Mueller', ['Schmidt']);
        $notice    = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller');
        $signal    = $this->scorer()->score($candidate, $notice);

        // given variant 8 + married 15 + born 20 = 43 (cap 45).
        self::assertSame(43, $signal->score);
        self::assertContains('birth surname matches', $signal->reasons);
        self::assertContains('married name matches', $signal->reasons);
    }

    /**
     * An identical full name scores the exact-name weight.
     */
    #[Test]
    public function fullNameExact(): void
    {
        $name   = new PersonName(['Otto'], null, 'Vorbild', null);
        $signal = $this->scorer()->score($name, $name);
        self::assertSame(40, $signal->score);
        self::assertContains('full name exact', $signal->reasons);
    }

    /**
     * A coincidental display-surname match without any surname role match must not credit
     * "full name exact": the conflicting maiden names mark different people, and the score
     * must stay coherent with the listed reasons (given-only contribution).
     */
    #[Test]
    public function displaySurnameOnlyMatchDoesNotCreditFullNameExact(): void
    {
        // Display surname "Schmidt" coincides, but the maiden names conflict (Mueller vs Wagner),
        // so no surname role matches.
        $candidate = new PersonName(['Anna'], null, 'Schmidt', 'Mueller');
        $notice    = new PersonName(['Anna'], null, 'Schmidt', 'Wagner');
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertNotContains('full name exact', $signal->reasons);
        self::assertContains('no surname role matched', $signal->reasons);

        // Given name "Anna" matches exactly (+10); no surname role contributes.
        self::assertSame(10, $signal->score);
    }

    /**
     * A given-name match without any surname role match never scores negative.
     */
    #[Test]
    public function noSurnameRoleMatchesReturnsZeroNotNegative(): void
    {
        $candidate = new PersonName(['Maria'], null, 'Becker', 'Becker');
        $notice    = new PersonName(['Maria'], null, 'Schmidt', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        // Given name "Maria" matches (+10) but no surname role matches; never negative.
        self::assertGreaterThanOrEqual(0, $signal->score);
        self::assertContains('no surname role matched', $signal->reasons);
    }

    /**
     * A phonetic-only surname match is a soft bonus weaker than an exact role match.
     */
    #[Test]
    public function phoneticSurnameIsSoftBonusOnly(): void
    {
        $candidate = new PersonName(['Hans'], null, 'Meier', 'Meier');
        $notice    = new PersonName(['Hans'], null, 'Mayer', null);
        $signal    = $this->scorer()->score($candidate, $notice);
        self::assertContains('surname phonetic', $signal->reasons);
        // phonetic (8) is weaker than an exact surname role (>=10).
        self::assertLessThan(30, $signal->score);
    }

    /**
     * Two different non-codeable tokens that both reduce to an empty phonetic key must not
     * award a phonetic surname role: the Cologne encoder maps any non-codeable input (a bare
     * consonant, digits, punctuation) to '', so an untrusted scraped notice surname like "H"
     * would otherwise collide with an unrelated candidate birth surname like "123".
     */
    #[Test]
    public function emptyPhoneticKeysDoNotAwardSurnamePhonetic(): void
    {
        // Notice surname "H" and candidate birth surname "123" both encode to '' but are unrelated.
        $candidate = new PersonName(['Maria'], null, '123', '123');
        $notice    = new PersonName(['Maria'], null, 'H', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertNotContains('surname phonetic', $signal->reasons);
        self::assertContains('no surname role matched', $signal->reasons);

        // Given name "Maria" matches exactly (+10); no surname role contributes.
        self::assertSame(10, $signal->score);
    }

    /**
     * A genuine phonetic surname match between Cologne-equivalent spellings still earns the
     * soft phonetic bonus of 8 points (over and above the given-name contribution).
     */
    #[Test]
    public function genuinePhoneticSurnameMatchStillEarnsBonus(): void
    {
        // Meyer/Maier reduce to the same Cologne key; no exact role matches.
        $candidate = new PersonName(['Hans'], null, 'Meyer', 'Meyer');
        $notice    = new PersonName(['Hans'], null, 'Maier', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertContains('surname phonetic', $signal->reasons);

        // Given name "Hans" matches exactly (+10) + phonetic surname (+8) = 18.
        self::assertSame(18, $signal->score);
    }

    /**
     * The total name score never exceeds the configured cap.
     */
    #[Test]
    public function neverExceedsCap(): void
    {
        $candidate = new PersonName(['Otto', 'Hans'], null, 'Vorbild', 'Vorbild', ['Vorbild']);
        $notice    = new PersonName(['Otto', 'Hans'], null, 'Vorbild', 'Vorbild');
        self::assertLessThanOrEqual(45, $this->scorer()->score($candidate, $notice)->score);
    }

    /**
     * A given name matching exactly must never score lower than the same role-rich case
     * matching the given name only as a variant.
     */
    #[Test]
    public function fullExactNeverScoresBelowVariant(): void
    {
        // Role-rich case: notice surname == candidate married name, notice geb. == candidate birth surname.
        $exactCandidate = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller', ['Schmidt']);
        $exactNotice    = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller');
        $exactScore     = $this->scorer()->score($exactCandidate, $exactNotice)->score;

        // Same roles, but the given name matches only as a variant (Elise/Elisabeth cluster).
        $variantCandidate = new PersonName(['Elise'], null, 'Schmidt', 'Mueller', ['Schmidt']);
        $variantNotice    = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller');
        $variantScore     = $this->scorer()->score($variantCandidate, $variantNotice)->score;

        self::assertGreaterThanOrEqual($variantScore, $exactScore);
    }

    /**
     * Two empty surnames must not award a surname role or a full-name-exact bonus.
     */
    #[Test]
    public function emptySurnamesOnBothSidesMatchNoRole(): void
    {
        $candidate = new PersonName(['Maria'], null, '', '');
        $notice    = new PersonName(['Maria'], null, '', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertContains('no surname role matched', $signal->reasons);
        self::assertNotContains('full name exact', $signal->reasons);
        self::assertNotContains('surname phonetic', $signal->reasons);

        // Given name "Maria" matches exactly (+10); no surname role contributes.
        self::assertSame(10, $signal->score);
    }

    /**
     * A notice carrying only an empty birth surname must not award a born-surname role
     * against a candidate whose canonical birth surname is empty.
     */
    #[Test]
    public function emptyBirthSurnamesMatchNoBornRole(): void
    {
        $candidate = new PersonName(['Maria'], null, 'Becker', '');
        $notice    = new PersonName(['Maria'], null, 'Becker', '');
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertNotContains('birth surname matches', $signal->reasons);
    }

    /**
     * A married-name match against a conflicting maiden name marks different people and must
     * not credit "full name exact": the score must stay at its role contribution.
     */
    #[Test]
    public function marriedMatchWithConflictingMaidenDoesNotCreditFullNameExact(): void
    {
        // Candidate Anna Schmidt geb. Mueller, married Schmidt; notice Anna Schmidt geb. Wagner.
        // The display surname and a married role match, but the maiden names conflict
        // (Mueller vs Wagner) -> different people.
        $candidate = new PersonName(['Anna'], null, 'Schmidt', 'Mueller', ['Schmidt']);
        $notice    = new PersonName(['Anna'], null, 'Schmidt', 'Wagner');
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertNotContains('full name exact', $signal->reasons);
        self::assertContains('married name matches', $signal->reasons);

        // Given exact 10 + married 15 = 25; no full-name-exact bonus.
        self::assertSame(25, $signal->score);
    }

    /**
     * Two given names that both normalise to an empty string (e.g. academic titles stripped to '')
     * must never count as an exact or variant given-name match: an empty key is not a name.
     */
    #[Test]
    public function titleOnlyGivenNamesDoNotMatch(): void
    {
        // Candidate given "Dr." and notice given "Prof." both normalise to ''; surnames differ.
        $candidate = new PersonName(['Dr.'], null, 'Schmidt', 'Schmidt');
        $notice    = new PersonName(['Prof.'], null, 'Wagner', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertNotContains('given name exact', $signal->reasons);
        self::assertNotContains('given name variant', $signal->reasons);

        // No given credit and no surname role -> 0.
        self::assertSame(0, $signal->score);
        self::assertContains('no surname role matched', $signal->reasons);
    }

    /**
     * A notice carrying only a birth surname (all display tokens consumed, e.g. "geb. Mueller")
     * must still award the born-surname role against a candidate whose birth surname matches,
     * even though the notice display surname is empty.
     */
    #[Test]
    public function gebOnlyNoticeRecoversBornSurnameOnEmptyNoticeSurname(): void
    {
        // Notice: surname '', birth surname "Mueller". Candidate birth surname "Mueller".
        $candidate = new PersonName(['Maria'], null, 'Mueller', 'Mueller');
        $notice    = new PersonName(['Maria'], null, '', 'Mueller');
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertContains('birth surname matches', $signal->reasons);

        // Given exact 10 + born surname 20 = 30; not 0.
        self::assertSame(30, $signal->score);
    }

    /**
     * When a candidate's birth surname equals its display surname (the common single-SURN GEDCOM
     * shape) and the notice agrees, only the higher-value born-surname role may fire: the two
     * surname roles are mutually exclusive, so the same string equality must not be double-credited
     * as both "birth surname matches" and "surname matches birth name".
     */
    #[Test]
    public function bornAndBirthNameSurnameRolesAreMutuallyExclusive(): void
    {
        // Candidate and notice share birth surname == display surname "Beispiel"; the given names
        // differ (Hans vs Klaus) so the full-name-exact bonus cannot mask the surname over-credit.
        $candidate = new PersonName(['Hans'], null, 'Beispiel', 'Beispiel');
        $notice    = new PersonName(['Klaus'], null, 'Beispiel', 'Beispiel');
        $signal    = $this->scorer()->score($candidate, $notice);

        // Born surname 20 only; not born 20 + birth-name 10 = 30.
        self::assertSame(20, $signal->score);
        self::assertContains('birth surname matches', $signal->reasons);
        self::assertNotContains('surname matches birth name', $signal->reasons);
    }

    /**
     * A candidate whose birth surname is an empty string (rather than null) must fall back to
     * its display surname for the surname-role comparison: "" ?? $surname would yield "" and
     * silently lose the role, whereas "" ?: $surname recovers the display surname.
     */
    #[Test]
    public function emptyBirthSurnameFallsBackToDisplaySurname(): void
    {
        // Candidate birth surname is '' (empty, not null); display surname is "Becker".
        $candidate = new PersonName(['Maria'], null, 'Becker', '');
        $notice    = new PersonName(['Maria'], null, 'Becker', null);
        $signal    = $this->scorer()->score($candidate, $notice);

        // Surname matches the candidate's display surname via the birth-name role (+10),
        // given name "Maria" matches exactly (+10) -> coherent, non-zero surname role.
        self::assertContains('surname matches birth name', $signal->reasons);
        self::assertNotContains('no surname role matched', $signal->reasons);
    }

    /**
     * The reported score of a role-rich exact match equals the sum of its reasons (capped).
     */
    #[Test]
    public function fullExactRoleRichScoreIsCoherent(): void
    {
        // Given exact 10 + born surname 20 + married 15 = 45 (cap maxName 45).
        $candidate = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller', ['Schmidt']);
        $notice    = new PersonName(['Elisabeth'], null, 'Schmidt', 'Mueller');
        $signal    = $this->scorer()->score($candidate, $notice);

        self::assertSame(45, $signal->score);
        self::assertContains('given name exact', $signal->reasons);
        self::assertContains('birth surname matches', $signal->reasons);
        self::assertContains('married name matches', $signal->reasons);
        self::assertContains('full name exact', $signal->reasons);
    }
}
