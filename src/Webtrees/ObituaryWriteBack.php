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
use MagicSunday\ObituaryMatcher\Support\UrlNormalizer;

use function date;
use function is_string;
use function mb_strtolower;
use function parse_url;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

use const PHP_URL_HOST;

/**
 * Writes the obituary's death date into a tree person as a sourced GEDCOM DEAT fact (2d-3a). It is
 * the only framework-facing write unit — it finds-or-creates a per-portal SOUR (one per canonical
 * host, identified by a REFN marker, pending-aware so a not-yet-accepted source is not duplicated),
 * writes the DEAT with an inline citation, and returns the {@see WriteBack} IDs. It is deliberately
 * store-agnostic: the caller marks the store confirmed AFTER a successful write.
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
     * @param PortalSourceRepository|null $sources The portal-source row reader; defaults to a fresh instance.
     */
    public function __construct(?PortalSourceRepository $sources = null)
    {
        $this->sources = $sources ?? new PortalSourceRepository();
    }

    /**
     * Writes the obituary death date into the individual as a sourced DEAT fact and returns the
     * write-back IDs. GEDCOM-only — the caller marks the store confirmed after a successful return.
     *
     * @param Individual $individual   The tree person to write to.
     * @param string     $isoDeathDate The exact ISO death date from the obituary.
     * @param string     $obituaryUrl  The source notice URL (the citation PAGE).
     *
     * @return WriteBack The IDs of the written records.
     *
     * @throws WriteBackPreconditionException   When the URL is not a clean http(s) single-line value.
     * @throws MalformedDeathDateException      When the death date is not an exact calendar date.
     * @throws DeathDateAlreadyPresentException When the person gained a death date before the write.
     */
    public function writeDeath(Individual $individual, string $isoDeathDate, string $obituaryUrl): WriteBack
    {
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

        // The host is embedded in the SOUR TITL/PUBL/REFN — guard it against GEDCOM-line injection too,
        // even though normalizeForIdentity/parse_url normally strip control chars.
        if (preg_match('/[\x00-\x1F\x7F]/', $host) === 1) {
            throw new WriteBackPreconditionException('The obituary host contains control characters.');
        }

        // Throws MalformedDeathDateException on a non-exact/calendar-invalid date, before any write.
        $gedcomDate = GedcomDateConverter::toGedcom($isoDeathDate);

        // Live re-check immediately before the create: the person must still have no death date
        // (covers DEAT/BURI/CREM). Closes the gate↔write TOCTOU; never silently succeeds.
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

        // The citation recording date in GEDCOM format (uppercase month). date('d M Y') would emit a
        // mixed-case "Sep"/"Dec" — convert today's ISO through the same converter so 4 DATE is GEDCOM-valid.
        $confirmDate = GedcomDateConverter::toGedcom(date('Y-m-d'));

        $deatGedcom = sprintf(
            "1 DEAT\n2 DATE %s\n2 SOUR @%s@\n3 PAGE %s\n3 DATA\n4 DATE %s",
            $gedcomDate,
            $sourceXref,
            $obituaryUrl,
            $confirmDate
        );

        $individual->createFact($deatGedcom, true);

        $deatFactId = $this->captureDeatFactId($individual, $gedcomDate, $sourceXref, $obituaryUrl);

        return new WriteBack($deatFactId, $sourceXref, $sourceCreated);
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
     * Derives the canonical host (lowercase, leading "www." stripped) from a source URL. This is NOT a
     * validator: `writeDeath` checks the http(s) scheme + control chars BEFORE calling it; an unparseable
     * URL here simply yields an empty string, which `writeDeath` then rejects.
     *
     * @param string $url The source notice URL.
     *
     * @return string The canonical host, or an empty string when unparseable.
     */
    protected function canonicalHost(string $url): string
    {
        $host = parse_url(UrlNormalizer::normalizeForIdentity($url), PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        $host = mb_strtolower($host, 'UTF-8');

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }

    /**
     * Finds an existing per-portal source for the host across BOTH accepted sources and this tree's
     * pending changes, so a source created earlier in the same (auto-accept-off) session is reused
     * rather than duplicated. On a pre-existing duplicate REFN the first match (accepted before
     * pending, each in id/change order) wins.
     *
     * @param Tree   $tree The tree to search.
     * @param string $host The canonical host.
     *
     * @return Source|null The matching source, or null when none exists.
     */
    protected function findPortalSource(Tree $tree, string $host): ?Source
    {
        $refn = self::REFN_PREFIX . $host;

        foreach ($this->sources->acceptedSources($tree) as $row) {
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
