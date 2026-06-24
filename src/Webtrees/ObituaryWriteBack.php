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
use MagicSunday\ObituaryMatcher\Support\GedcomDateConverter;
use MagicSunday\ObituaryMatcher\Support\MalformedDeathDateException;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

use function count;
use function date;
use function is_string;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Writes the obituary's facts into a tree person via {@see writeConfirm()} — a sourced GEDCOM DEAT
 * fact, and (from Task 2) a sourced BURI when a cemetery is present. It is the only framework-facing
 * write unit — it finds-or-creates a per-portal SOUR (one per canonical host, identified by a REFN
 * marker, pending-aware so a not-yet-accepted source is not duplicated), writes the facts with an
 * inline citation, and returns the {@see WriteBack} IDs. It is deliberately store-agnostic: the
 * caller marks the store confirmed AFTER a successful write. {@see writeDeath()} is retained as a
 * thin back-compat wrapper (a confirm with no cemetery).
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
     * Writes the obituary's facts (a sourced DEAT, and a sourced BURI when a cemetery is present)
     * into the individual and returns the write-back IDs. GEDCOM-only — the caller marks the store
     * confirmed after a successful return. All preconditions run before any record is created.
     *
     * @param Individual  $individual  The tree person to write to.
     * @param string      $isoDeath    The exact ISO death date from the obituary.
     * @param string|null $cemetery    The extracted cemetery name, or null when none was found.
     * @param string|null $funeralIso  The exact ISO funeral date, or null when none/non-exact.
     * @param string      $obituaryUrl The source notice URL (the citation PAGE).
     *
     * @return WriteBack The IDs of the written records.
     *
     * @throws WriteBackPreconditionException   When the URL/host/cemetery is not a clean value.
     * @throws MalformedDeathDateException      When the death (or a cemetery's funeral) date is not exact.
     * @throws DeathDateAlreadyPresentException When the person gained a death date before the write.
     */
    public function writeConfirm(
        Individual $individual,
        string $isoDeath,
        ?string $cemetery,
        ?string $funeralIso,
        string $obituaryUrl,
    ): WriteBack {
        // Precondition: a clean, single-line http(s) URL (no GEDCOM-line injection via 3 PAGE).
        if (
            (preg_match('~^https?://~i', $obituaryUrl) !== 1)
            || (preg_match('/[\x00-\x1F\x7F]/', $obituaryUrl) === 1)
        ) {
            throw new WriteBackPreconditionException('The obituary URL is not a clean http(s) single-line value.');
        }

        $host = $this->canonicalHost($obituaryUrl);

        if ($host === '') {
            throw new WriteBackPreconditionException('The obituary URL has no parseable host.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $host) === 1) {
            throw new WriteBackPreconditionException('The obituary host contains control characters.');
        }

        // Throws MalformedDeathDateException on a non-exact/calendar-invalid date, before any write.
        $deathGedcom = GedcomDateConverter::toGedcom($isoDeath);

        // Normalise the cemetery at THIS boundary too (the handler is not the only caller): trim, and
        // treat whitespace-only / empty as absent so we never emit a blank `2 PLAC`.
        $cleanCemetery = is_string($cemetery) ? trim($cemetery) : null;
        $cleanCemetery = ($cleanCemetery === '') ? null : $cleanCemetery;

        // The cemetery is untrusted free text — a control char would inject a GEDCOM sub-record into the
        // 2 PLAC line. Reject before any write so the confirm aborts atomically.
        if (($cleanCemetery !== null) && (preg_match('/[\x00-\x1F\x7F]/', $cleanCemetery) === 1)) {
            throw new WriteBackPreconditionException('The cemetery name contains control characters.');
        }

        // The funeral date is only relevant to the optional BURI: validate it ONLY when a cemetery is
        // present, so a no-cemetery confirm with a malformed funeral date keeps the exact 2d-3a DEAT
        // behaviour (no BURI, no abort). Throws MalformedDeathDateException when present + malformed.
        $funeralGedcom = null;

        if (($cleanCemetery !== null) && ($funeralIso !== null)) {
            $funeralGedcom = GedcomDateConverter::toGedcom($funeralIso);
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

        $deatFactId = $this->writeDeathFact($individual, $deathGedcom, $sourceXref, $obituaryUrl);
        $buriFactId = $this->writeBurialFact($individual, $cleanCemetery, $funeralGedcom, $sourceXref, $obituaryUrl);

        return new WriteBack($deatFactId, $sourceXref, $sourceCreated, $buriFactId);
    }

    /**
     * Writes the obituary death date into the individual as a sourced DEAT fact. Thin back-compat
     * wrapper over {@see writeConfirm()} (a confirm with no cemetery).
     *
     * @param Individual $individual   The tree person to write to.
     * @param string     $isoDeathDate The exact ISO death date from the obituary.
     * @param string     $obituaryUrl  The source notice URL (the citation PAGE).
     *
     * @return WriteBack The IDs of the written records.
     */
    public function writeDeath(Individual $individual, string $isoDeathDate, string $obituaryUrl): WriteBack
    {
        return $this->writeConfirm($individual, $isoDeathDate, null, null, $obituaryUrl);
    }

    /**
     * Writes the sourced DEAT fact and returns its captured fact id.
     *
     * @param Individual $individual  The tree person.
     * @param string     $deathGedcom The GEDCOM death date.
     * @param string     $sourceXref  The portal source xref to cite.
     * @param string     $obituaryUrl The citation PAGE.
     *
     * @return string The written DEAT fact id.
     *
     * @throws WriteBackPreconditionException When the written fact cannot be located.
     */
    private function writeDeathFact(Individual $individual, string $deathGedcom, string $sourceXref, string $obituaryUrl): string
    {
        // The citation recording date in GEDCOM format (uppercase month). date('d M Y') would emit a
        // mixed-case "Sep"/"Dec" — convert today's ISO through the same converter so 4 DATE is GEDCOM-valid.
        $confirmDate = GedcomDateConverter::toGedcom(date('Y-m-d'));

        $deatGedcom = sprintf(
            "1 DEAT\n2 DATE %s\n2 SOUR @%s@\n3 PAGE %s\n3 DATA\n4 DATE %s",
            $deathGedcom,
            $sourceXref,
            $obituaryUrl,
            $confirmDate
        );

        $individual->createFact($deatGedcom, true);

        return $this->captureDeatFactId($individual, $deathGedcom, $sourceXref, $obituaryUrl);
    }

    /**
     * Writes a sourced BURI fact when a cemetery is present and the individual has no existing burial,
     * and returns its captured fact id (null when no BURI was written).
     *
     * @param Individual  $individual    The tree person.
     * @param string|null $cemetery      The validated, non-empty cemetery name, or null.
     * @param string|null $funeralGedcom The GEDCOM funeral date, or null.
     * @param string      $sourceXref    The portal source xref to cite.
     * @param string      $obituaryUrl   The citation PAGE.
     *
     * @return string|null The written BURI fact id, or null when none was written.
     *
     * @throws WriteBackPreconditionException When a written BURI cannot be located.
     */
    private function writeBurialFact(Individual $individual, ?string $cemetery, ?string $funeralGedcom, string $sourceXref, string $obituaryUrl): ?string
    {
        // Never create a second BURI: an existing burial may carry place/date/notes a duplicate would
        // shadow. Merging into it is a later hardening slice. (count() matches the existing deatCount()
        // helper — facts() returns a Countable collection.)
        if (($cemetery === null) || (count($individual->facts(['BURI'], false, null, true)) > 0)) {
            return null;
        }

        $confirmDate = GedcomDateConverter::toGedcom(date('Y-m-d'));

        $buriGedcom = '1 BURI';

        if ($funeralGedcom !== null) {
            $buriGedcom .= "\n2 DATE " . $funeralGedcom;
        }

        $buriGedcom .= sprintf(
            "\n2 PLAC %s\n2 SOUR @%s@\n3 PAGE %s\n3 DATA\n4 DATE %s",
            $cemetery,
            $sourceXref,
            $obituaryUrl,
            $confirmDate
        );

        $individual->createFact($buriGedcom, true);

        return $this->captureBuriFactId($individual, $cemetery, $sourceXref, $obituaryUrl);
    }

    /**
     * Finds the just-written DEAT fact (by its DATE + SOUR + PAGE substrings) and returns its id.
     *
     * @param Individual $individual  The individual the fact was written to.
     * @param string     $gedcomDate  The GEDCOM date written.
     * @param string     $sourceXref  The cited source xref.
     * @param string     $obituaryUrl The citation PAGE.
     *
     * @return string The fact id.
     *
     * @throws WriteBackPreconditionException When the written fact cannot be located (should not happen).
     */
    private function captureDeatFactId(Individual $individual, string $gedcomDate, string $sourceXref, string $obituaryUrl): string
    {
        foreach ($individual->facts(['DEAT'], false, null, true) as $fact) {
            $gedcom = $fact->gedcom();

            if (
                str_contains($gedcom, '2 DATE ' . $gedcomDate)
                && str_contains($gedcom, '2 SOUR @' . $sourceXref . '@')
                && str_contains($gedcom, '3 PAGE ' . $obituaryUrl)
            ) {
                return $fact->id();
            }
        }

        throw new WriteBackPreconditionException('The written DEAT fact could not be located.');
    }

    /**
     * Finds the just-written BURI fact (by its PLAC + SOUR + PAGE substrings) and returns its id.
     *
     * @param Individual $individual  The individual the fact was written to.
     * @param string     $cemetery    The PLAC written.
     * @param string     $sourceXref  The cited source xref.
     * @param string     $obituaryUrl The citation PAGE.
     *
     * @return string The fact id.
     *
     * @throws WriteBackPreconditionException When the written BURI cannot be located.
     */
    private function captureBuriFactId(Individual $individual, string $cemetery, string $sourceXref, string $obituaryUrl): string
    {
        foreach ($individual->facts(['BURI'], false, null, true) as $fact) {
            $gedcom = $fact->gedcom();

            if (
                str_contains($gedcom, '2 PLAC ' . $cemetery)
                && str_contains($gedcom, '2 SOUR @' . $sourceXref . '@')
                && str_contains($gedcom, '3 PAGE ' . $obituaryUrl)
            ) {
                return $fact->id();
            }
        }

        throw new WriteBackPreconditionException('The written BURI fact could not be located.');
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
        if (preg_match('/[\x00-\x1F\x7F]/', $host) === 1) {
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
