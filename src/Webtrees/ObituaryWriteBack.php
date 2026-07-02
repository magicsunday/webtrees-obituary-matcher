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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use MagicSunday\ObituaryMatcher\Matching\WriteBack;
use MagicSunday\ObituaryMatcher\Support\ControlChars;
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Support\MalformedDeathDateException;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

use function count;
use function date;
use function md5;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Writes the obituary's facts into a tree person via {@see writeConfirm()} — a sourced GEDCOM DEAT
 * fact, plus a disposition event: a sourced BURI when a cemetery is present, or (for a cremation notice)
 * a sourced CREM for any cremation, with the place recorded only when known. It is the only
 * framework-facing
 * write unit — it finds-or-creates a per-portal SOUR (one per canonical host, identified by a REFN
 * marker, pending-aware so a not-yet-accepted source is not duplicated), writes the facts with an
 * inline citation, and returns the {@see WriteBack} IDs. It is deliberately store-agnostic: the
 * caller marks the store confirmed AFTER a successful write.
 *
 * Intentionally non-final: integration tests subclass it to drive the protected source/host seams
 * (`findPortalSource`/`createPortalSource`/`canonicalHost`) over a real tree — the same test-seam
 * exception 2d-2/#26 made for `ReviewScreenHandler`/`ObituaryMatcherModule`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
class ObituaryWriteBack
{
    /**
     * The REFN marker prefix that identifies a per-portal source created by this module.
     */
    private const string REFN_PREFIX = 'obituary-matcher:portal:';

    /**
     * The SQL surface that reads the accepted + pending source rows the find scan unions over.
     */
    private readonly PortalSourceRepository $sources;

    /**
     * Constructor.
     *
     * @param PortalSourceRepository|null $sources        The portal-source row reader; defaults to a fresh instance.
     * @param UrlHostNormalizer           $hostNormalizer The shared canonical-host helper.
     */
    public function __construct(
        ?PortalSourceRepository $sources = null,
        private readonly UrlHostNormalizer $hostNormalizer = new UrlHostNormalizer(),
    ) {
        $this->sources = $sources ?? new PortalSourceRepository();
    }

    /**
     * Writes the obituary's facts (a sourced DEAT, plus a disposition event — a sourced BURI when a
     * cemetery is present, or a sourced CREM for a cremation regardless of place) into the individual and
     * returns the write-back IDs. GEDCOM-only — the caller marks the store confirmed after a successful
     * return. All preconditions run before any record is created.
     *
     * @param Individual  $individual  The tree person to write to.
     * @param string      $isoDeath    The exact ISO death date from the obituary.
     * @param string|null $cemetery    The extracted cemetery name, or null when none was found.
     * @param string|null $funeralIso  The exact ISO funeral date, or null when none/non-exact.
     * @param string      $obituaryUrl The source notice URL (the citation PAGE).
     * @param bool        $cremation   Whether the notice is a cremation — writes a sourced CREM instead of
     *                                 a BURI (the two are mutually exclusive per notice). Defaults to false
     *                                 (burial).
     *
     * @return WriteBack The IDs of the written records.
     *
     * @throws WriteBackPreconditionException   When the URL/host/cemetery is not a clean value.
     * @throws MalformedDeathDateException      When the death (or the written event's funeral) date is not exact.
     * @throws DeathDateAlreadyPresentException When the person gained a death date before the write.
     */
    public function writeConfirm(
        Individual $individual,
        string $isoDeath,
        ?string $cemetery,
        ?string $funeralIso,
        string $obituaryUrl,
        bool $cremation = false,
    ): WriteBack {
        // Precondition: a clean, single-line http(s) URL (no GEDCOM-line injection via 3 PAGE).
        if (
            (preg_match('~^https?://~i', $obituaryUrl) !== 1)
            || ControlChars::contains($obituaryUrl)
        ) {
            throw new WriteBackPreconditionException('The obituary URL is not a clean http(s) single-line value.');
        }

        $host = $this->canonicalHost($obituaryUrl);

        if ($host === '') {
            throw new WriteBackPreconditionException('The obituary URL has no parseable host.');
        }

        if (ControlChars::contains($host)) {
            throw new WriteBackPreconditionException('The obituary host contains control characters.');
        }

        // Throws MalformedDeathDateException on a non-exact/calendar-invalid date, before any write.
        $deathGedcom = GedcomDateConverter::toGedcom($isoDeath);

        // Normalise the cemetery at THIS boundary too (the handler is not the only caller): trim, and
        // treat whitespace-only / empty as absent so we never emit a blank `2 PLAC`.
        $cleanCemetery = $cemetery !== null ? trim($cemetery) : null;
        $cleanCemetery = ($cleanCemetery === '') ? null : $cleanCemetery;

        // The cemetery is untrusted free text — a control char would inject a GEDCOM sub-record into the
        // 2 PLAC line. Reject before any write so the confirm aborts atomically.
        if (
            ($cleanCemetery !== null)
            && ControlChars::contains($cleanCemetery)
        ) {
            throw new WriteBackPreconditionException('The cemetery name contains control characters.');
        }

        // Normalise the funeral date at THIS boundary too (mirroring the cemetery normalisation, for
        // robustness against direct callers): trim, and treat whitespace-only / empty as absent.
        $cleanFuneralIso = $funeralIso !== null ? trim($funeralIso) : null;
        $cleanFuneralIso = ($cleanFuneralIso === '') ? null : $cleanFuneralIso;

        // The funeral date is relevant to the disposition event that will actually be written: a BURI is
        // written only WITH a cemetery, whereas a CREM is written for any cremation (place-optional, since
        // a cremation is a fact even when the crematorium is not stated). So validate the funeral date
        // whenever the event is due — a cremation, or a burial that has a cemetery — and otherwise keep the
        // exact no-event DEAT behaviour (a no-cemetery burial with a malformed funeral date does not
        // abort). Throws MalformedDeathDateException when present + malformed.
        $funeralGedcom = null;

        if (
            ($cleanFuneralIso !== null)
            && ($cremation || ($cleanCemetery !== null))
        ) {
            $funeralGedcom = GedcomDateConverter::toGedcom($cleanFuneralIso);
        }

        // Live re-check immediately before the create: the person must still have no death date
        // (covers a DATED DEAT/BURI/CREM). Closes the gate↔write TOCTOU; never silently succeeds.
        if ($individual->getDeathDate()->isOK()) {
            throw new DeathDateAlreadyPresentException('The individual already has a death date.');
        }

        $existing = $this->findPortalSource($individual->tree(), $host);

        if ($existing instanceof Source) {
            $source        = $existing;
            $sourceCreated = false;
        } else {
            $source        = $this->createPortalSource($individual->tree(), $host);
            $sourceCreated = true;
        }

        $sourceXref = $source->xref();

        // The citation recording date in GEDCOM format (uppercase month). Read the clock ONCE so the
        // DEAT and the (optional) BURI cite the same instant. date('d M Y') would emit a mixed-case
        // "Sep"/"Dec" — convert today's ISO through the same converter so 4 DATE is GEDCOM-valid.
        $confirmDate = GedcomDateConverter::toGedcom(date('Y-m-d'));

        // A literal `@` in a GEDCOM value must be escaped to `@@` (it otherwise starts an XREF pointer);
        // webtrees stores the updateRecord() string verbatim (only a record-level CR/LF squash + trim +
        // an appended trailing `1 CHAN`, none of which alters a non-trailing fact), so the value is
        // escaped here once and reused below.
        $escapedUrl = str_replace('@', '@@', $obituaryUrl);

        // The DEAT fact gedcom written below. Its id is md5($deatGedcomFact), computed directly from the
        // string we write — no post-write re-read — so the orphan window is structurally impossible: the
        // single updateRecord either commits both facts or throws with nothing written.
        $deatGedcomFact = sprintf(
            "1 DEAT\n2 DATE %s\n2 SOUR @%s@\n3 PAGE %s\n3 DATA\n4 DATE %s",
            $deathGedcom,
            $sourceXref,
            $escapedUrl,
            $confirmDate
        );

        // A notice is either a burial OR a cremation, so the single cemetery/funeral routes to exactly one
        // event tag; the builder is otherwise identical (guarded on that same tag so a duplicate is never
        // written).
        // A burial is recorded only when a cemetery is known (a burial's information IS its place); a
        // cremation is recorded whenever the notice says so, since the cremation itself is the fact and a
        // crematorium place is often not stated. So BURI requires a place, CREM does not.
        $eventTag        = $cremation ? 'CREM' : 'BURI';
        $eventGedcomFact = $this->buildDispositionEvent($eventTag, !$cremation, $individual, $cleanCemetery, $funeralGedcom, $sourceXref, $escapedUrl, $confirmDate);

        // ONE write commits the DEAT and the (optional) BURI/CREM atomically. webtrees parses each fact id
        // as md5 of the stored fact gedcom (GedcomRecord::parseFacts), and stores the fact gedcom verbatim,
        // so md5($deatGedcomFact) === the stored DEAT fact's id() (pinned by the verification test) — the
        // Revert resolves the facts by these computed ids.
        $newGedcom = $individual->gedcom() . "\n" . $deatGedcomFact;

        if ($eventGedcomFact !== null) {
            $newGedcom .= "\n" . $eventGedcomFact;
        }

        $individual->updateRecord($newGedcom, true);

        $eventFactId = $eventGedcomFact !== null ? md5($eventGedcomFact) : null;

        return new WriteBack(
            md5($deatGedcomFact),
            $sourceXref,
            $sourceCreated,
            $cremation ? null : $eventFactId,
            $cremation ? $eventFactId : null,
        );
    }

    /**
     * Builds the sourced disposition-event fact gedcom (a `BURI` or a `CREM`, per $tag) when a cemetery is
     * present and the individual has no existing event of that tag, or returns null when none must be
     * written (no cemetery, or an existing event we never shadow with a duplicate). The DATE line precedes
     * the PLAC line, and the free-text cemetery is the caller's already-trimmed value; the obituary URL is
     * the caller's already-`@@`-escaped value. BURI and CREM share this shape — GEDCOM models both as a
     * dated, placed, source-citable death event.
     *
     * @param string      $tag           The event tag to write — `BURI` or `CREM`.
     * @param bool        $requiresPlace Whether the event is written ONLY when a cemetery is present (BURI)
     *                                   or unconditionally for the disposition (CREM, place-optional).
     * @param Individual  $individual    The tree person.
     * @param string|null $cemetery      The validated, non-empty cemetery name, or null.
     * @param string|null $funeralGedcom The GEDCOM funeral date, or null.
     * @param string      $sourceXref    The portal source xref to cite.
     * @param string      $escapedUrl    The `@@`-escaped citation PAGE URL.
     * @param string      $confirmDate   The GEDCOM citation recording date (read once per confirm).
     *
     * @return string|null The disposition-event fact gedcom, or null when none must be written.
     */
    private function buildDispositionEvent(string $tag, bool $requiresPlace, Individual $individual, ?string $cemetery, ?string $funeralGedcom, string $sourceXref, string $escapedUrl, string $confirmDate): ?string
    {
        // Skip when the event needs a place it does not have (BURI without a cemetery), and never create a
        // second event of this tag: an existing burial/cremation may carry place/date/notes a duplicate
        // would shadow. Merging into it is a later hardening slice. (count() matches the existing
        // deatCount() helper — facts() returns a Countable collection.)
        if (
            ($requiresPlace && ($cemetery === null))
            || (count($individual->facts([$tag], false, null, true)) > 0)
        ) {
            return null;
        }

        $eventGedcom = '1 ' . $tag;

        if ($funeralGedcom !== null) {
            $eventGedcom .= "\n2 DATE " . $funeralGedcom;
        }

        // The PLAC is emitted only when a cemetery/place is known (a place-optional CREM may have none). A
        // literal `@` in the free-text cemetery must be escaped to `@@` (it otherwise starts an XREF
        // pointer); webtrees stores the fact verbatim.
        if ($cemetery !== null) {
            $eventGedcom .= "\n2 PLAC " . str_replace('@', '@@', $cemetery);
        }

        return $eventGedcom . sprintf(
            "\n2 SOUR @%s@\n3 PAGE %s\n3 DATA\n4 DATE %s",
            $sourceXref,
            $escapedUrl,
            $confirmDate
        );
    }

    /**
     * Returns the canonical host for the source URL, or an empty string when it cannot be
     * derived. Delegates to the shared {@see UrlHostNormalizer}; the helper returns null on a
     * bad host, which this method coalesces to `''` so the caller's `=== ''` precondition (and
     * the {@see WriteBackPreconditionException} it raises) keeps firing exactly as before.
     *
     * @param string $url The source notice URL.
     *
     * @return string The canonical host, or an empty string when unparseable.
     */
    protected function canonicalHost(string $url): string
    {
        return $this->hostNormalizer->canonicalHost($url) ?? '';
    }

    /**
     * Finds an existing per-portal source for the host across BOTH accepted sources and this tree's
     * pending changes, so a source created earlier in the same (auto-accept-off) session is reused
     * rather than duplicated. A pending edit overlays the accepted record (pending-wins), mirroring
     * {@see \Fisharebest\Webtrees\Factories\SourceFactory::make}: an accepted source whose xref also
     * carries a pending change is matched on its PENDING blob, never the stale accepted one. On a
     * pre-existing duplicate REFN the first match (accepted-non-superseded before pending, each in
     * id/change order) wins.
     *
     * @param Tree   $tree The tree to search.
     * @param string $host The canonical host.
     *
     * @return Source|null The matching source, or null when none exists.
     */
    protected function findPortalSource(Tree $tree, string $host): ?Source
    {
        $refn = self::REFN_PREFIX . $host;

        // The set of xrefs that carry ANY pending change (edit, create OR delete). An accepted source
        // whose xref is in this set is superseded — its authoritative current state is the pending one,
        // so the accepted scan must skip it (pending-wins overlay) and resolution happens in the pending
        // match loop instead. This includes a pending DELETE of an accepted source: its xref is in the
        // set (so the accepted blob is skipped) but absent from the filtered pendingSources() match set
        // below (so it is never resurrected), leaving find() to return null as core's overlay would.
        $pendingXrefs = $this->sources->pendingXrefs($tree);

        foreach ($this->sources->acceptedSources($tree, $refn) as $row) {
            // Skip an accepted row superseded by a pending change: its authoritative current state is the
            // pending blob, which the pending loop resolves under the (possibly changed) host — or nothing,
            // when the pending change is a delete.
            if (isset($pendingXrefs[$row['xref']])) {
                continue;
            }

            if (!$this->gedcomHasRefn($row['gedcom'], $refn)) {
                continue;
            }

            $source = Registry::sourceFactory()->make($row['xref'], $tree, $row['gedcom']);

            if ($source instanceof Source) {
                return $source;
            }
        }

        foreach ($this->sources->pendingSources($tree) as $row) {
            if (!$this->gedcomHasRefn($row['gedcom'], $refn)) {
                continue;
            }

            $source = Registry::sourceFactory()->make($row['xref'], $tree, $row['gedcom']);

            if ($source instanceof Source) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Creates the per-portal source record for the host (a pending change unless the user auto-accepts).
     *
     * @param Tree   $tree The tree to create the source in.
     * @param string $host The canonical host.
     *
     * @return Source The created source, carrying its allocated xref.
     *
     * @throws WriteBackPreconditionException When the host carries a control character, or the record
     *                                        could not be resolved after creation.
     */
    protected function createPortalSource(Tree $tree, string $host): Source
    {
        // A CR/LF or other control character in the host would break out of the GEDCOM REFN/TITL/PUBL
        // line and inject arbitrary sub-records, so reject it before it reaches createRecord().
        if (ControlChars::contains($host)) {
            throw new WriteBackPreconditionException('The source host carries a control character.');
        }

        $gedcom = sprintf(
            "0 @@ SOUR\n1 TITL Death notice: %s\n1 PUBL %s\n1 REFN %s%s",
            $host,
            $host,
            self::REFN_PREFIX,
            $host
        );

        $record = $tree->createRecord($gedcom);

        // Pass the created record's gedcom to make() — do NOT call make($xref, $tree) without it. Under
        // the default pending mode the new SOUR is only a pending change (no `sources` table row), and
        // SourceFactory::pendingChanges() is memoised in a request-scoped array cache that findPortalSource
        // already warmed (stale, without this xref) while scanning the accepted sources. Without the
        // gedcom arg, make() would read that stale cache, get null, and we'd wrongly throw. Passing the
        // gedcom resolves the record directly.
        return Registry::sourceFactory()->make($record->xref(), $tree, $record->gedcom())
            ?? throw new WriteBackPreconditionException('Failed to create the portal source record.');
    }

    /**
     * Whether a record's GEDCOM carries the given REFN value.
     *
     * @param string $gedcom The record GEDCOM.
     * @param string $refn   The REFN value to match.
     *
     * @return bool True when a `1 REFN <refn>` line is present.
     */
    private function gedcomHasRefn(string $gedcom, string $refn): bool
    {
        // Allow an optional trailing CR before the line boundary so a CRLF-stored record (a foreign
        // pending `change` row that webtrees does not CRLF-normalise) is not silently missed: in `/m`
        // mode `$` matches before `\n` but not before `\r\n`, leaving the `\r` unconsumed.
        return preg_match('/^1 REFN ' . preg_quote($refn, '/') . '(?=\r?\n|$)/m', $gedcom) === 1;
    }
}
