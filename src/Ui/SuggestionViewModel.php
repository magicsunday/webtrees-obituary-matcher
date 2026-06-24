<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\Band;
use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatchKey;

/**
 * A read-only, view-ready projection of a {@see StoredMatch} for the individual tab. It exposes the
 * trusted score, ambiguity and hard-conflict flags verbatim, normalises the classification to a
 * fixed allow-list (guarding against CSS-class injection from an unexpected band string), formats
 * the extracted death date as a German `DD.MM.YYYY` string and refuses any non-HTTP source URL so
 * a hostile `javascript:` scheme can never reach an anchor href.
 *
 * @phpstan-import-type ClassifiedMatchArray from ClassifiedMatch
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class SuggestionViewModel
{
    /**
     * Constructor.
     *
     * @param int         $score        The match score.
     * @param string      $bandKey      The normalised classification band (allow-list, defaults to "none").
     * @param string|null $deathDate    The German-formatted death date, or null when absent.
     * @param string|null $sourceUrl    The HTTP(S) source notice URL, or null when refused.
     * @param string|null $sourceHost   The source URL host, or null when unavailable.
     * @param string      $statusKey    The lifecycle status backing value.
     * @param bool        $ambiguous    Whether the engine flagged the match as ambiguous.
     * @param bool        $hardConflict Whether the engine flagged a hard conflict.
     * @param string      $rowKey       The canonical review-route row key.
     */
    public function __construct(
        public int $score,
        public string $bandKey,
        public ?string $deathDate,
        public ?string $sourceUrl,
        public ?string $sourceHost,
        public string $statusKey,
        public bool $ambiguous,
        public bool $hardConflict,
        public string $rowKey,
    ) {
    }

    /**
     * Projects a stored match into a view-ready model.
     *
     * @param StoredMatch $match The stored match to project.
     *
     * @return self The view-ready projection.
     */
    public static function fromStoredMatch(StoredMatch $match): self
    {
        // The payload is the trusted engine shape, but it was reconstructed from untrusted on-disk JSON
        // ({@see StoredMatch::fromArray()} only asserts it is an array — no per-key validation), so every
        // key is read through the shared {@see PayloadReader} narrowing seam and degraded defensively:
        // a malformed-but-array row (hand-edited / older schema) must render the individual-page tab
        // gracefully (band "none", score 0, null death date, false flags) rather than throw an
        // Undefined-array-key notice or a TypeError that would crash the tab for every visitor. This
        // narrows the payload IDENTICALLY to {@see WorklistPresenter} and {@see ReviewViewModel}.
        $payload = $match->match;

        $url    = $match->obituaryUrl;
        $source = SourceLink::fromUrl($url);

        $classification = PayloadReader::asString(
            PayloadReader::read($payload, 'classification'),
            Band::None->value(),
        );

        return new self(
            PayloadReader::asInt(PayloadReader::read($payload, 'score'), 0),
            BandKey::normalise($classification),
            ObituaryDateFormatter::toGerman(PayloadReader::nestedString($payload, 'extractedFacts', 'deathDate')),
            $source->href,
            $source->host,
            $match->status->value,
            PayloadReader::read($payload, 'ambiguous') === true,
            PayloadReader::read($payload, 'hardConflict') === true,
            StoredMatchKey::fromUrl($url),
        );
    }
}
