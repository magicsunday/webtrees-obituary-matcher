<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

/**
 * A single worklist row, projected webtrees-free. Every field is a PLAIN, untrusted string
 * (never pre-escaped HTML); the worklist template escapes each sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class WorklistRowView
{
    /**
     * Constructor.
     *
     * @param string      $personName  The tree individual's display name, reduced to plain text.
     * @param string      $personId    The individual XREF.
     * @param string      $personUrl   The internal individual-page URL (built by the handler).
     * @param string      $bandKey     The normalised classification band (allow-list).
     * @param string      $bandLabel   The human-readable band label.
     * @param int         $score       The match score (0 when the payload had none).
     * @param string|null $deathDate   The extracted death date as DD.MM.YYYY, or null.
     * @param string|null $sourceUrl   The http(s)-only source link, or null when not linkable.
     * @param string|null $sourceHost  The source host text, or null when unavailable.
     * @param string      $statusKey   The lifecycle status backing value.
     * @param string      $statusLabel The human-readable status label.
     * @param string|null $reviewUrl   The per-item review URL, or null for a terminal row.
     */
    public function __construct(
        public string $personName,
        public string $personId,
        public string $personUrl,
        public string $bandKey,
        public string $bandLabel,
        public int $score,
        public ?string $deathDate,
        public ?string $sourceUrl,
        public ?string $sourceHost,
        public string $statusKey,
        public string $statusLabel,
        public ?string $reviewUrl,
    ) {
    }
}
