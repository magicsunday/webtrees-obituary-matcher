<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use MagicSunday\ObituaryMatcher\Domain\ClassifiedMatch;
use MagicSunday\ObituaryMatcher\Matching\StoredMatch;

use function in_array;
use function is_string;
use function parse_url;
use function preg_match;

use const PHP_URL_HOST;

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
     * The classification values that may pass through to a CSS class.
     *
     * @var list<string>
     */
    private const array BANDS = ['strong', 'probable', 'possible', 'weak', 'none'];

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
        $payload = $match->match;

        $band = in_array($payload['classification'], self::BANDS, true)
            ? $payload['classification']
            : 'none';

        $url    = $match->obituaryUrl;
        $isHttp = preg_match('~^https?://~i', $url) === 1;

        $host = null;

        if ($isHttp) {
            $parsedHost = parse_url($url, PHP_URL_HOST);

            if (is_string($parsedHost) && ($parsedHost !== '')) {
                $host = $parsedHost;
            }
        }

        return new self(
            $payload['score'],
            $band,
            self::formatDeathDate($payload['extractedFacts']['deathDate'] ?? null),
            $isHttp ? $url : null,
            $host,
            $match->status->value,
            $payload['ambiguous'],
            $payload['hardConflict'],
        );
    }

    /**
     * Formats an ISO `YYYY-MM-DD` death date as a German `DD.MM.YYYY` string, passing any other
     * shape through unchanged.
     *
     * @param string|null $raw The raw extracted death date, or null when absent.
     *
     * @return string|null The formatted date, the unchanged raw value, or null.
     */
    private static function formatDeathDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches) === 1) {
            return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
        }

        return $raw;
    }
}
