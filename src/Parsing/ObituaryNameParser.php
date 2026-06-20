<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Parsing;

use MagicSunday\ObituaryMatcher\Domain\PersonName;

use function array_pop;
use function in_array;
use function preg_split;
use function strtolower;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Parses an obituary display name into given names, surname and a "geb." birth surname.
 *
 * MVP heuristic: this handles simple display names only — the last remaining token is
 * taken as the surname. Compound surnames ("Muster-Schmidt", "Becker-Müller") and
 * nobility particles ("von der Heide") are deferred to a later increment. The raw
 * display name is retained by the caller, so nothing is lost.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ObituaryNameParser
{
    /**
     * @var list<string> Marker words introducing the birth surname.
     */
    private const array BORN_MARKERS = ['geb.', 'geborene', 'gebürtige', 'gen.', 'genannt'];

    /**
     * @var list<string> Marker words introducing a widow/married surname (dropped for matching).
     */
    private const array WIDOW_MARKERS = ['verw.', 'verh.'];

    /**
     * Maximum number of raw input bytes processed; untrusted notice text is truncated to this length.
     */
    private const int MAX_RAW_LENGTH = 512;

    /**
     * Maximum number of tokens the raw display name is split into.
     */
    private const int MAX_TOKENS = 65;

    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Parses an obituary display name into given names, surname and birth surname.
     *
     * @param string $raw The raw display name from the notice.
     *
     * @return PersonName The parsed name (raw retained separately by the caller).
     */
    public static function parse(string $raw): PersonName
    {
        $bounded = substr(trim($raw), 0, self::MAX_RAW_LENGTH);
        $split   = preg_split('/\s+/', $bounded, self::MAX_TOKENS, PREG_SPLIT_NO_EMPTY);
        $tokens  = ($split !== false) ? $split : [];

        $birthSurname = self::extractMarked($tokens, self::BORN_MARKERS);
        self::extractMarked($tokens, self::WIDOW_MARKERS); // dropped for matching

        $surname = ($tokens === []) ? '' : array_pop($tokens);

        return new PersonName(
            $tokens,
            null,
            $surname,
            ($birthSurname === '') ? null : $birthSurname,
        );
    }

    /**
     * Removes "<marker> <name>" from $tokens (by reference) and returns the captured name.
     *
     * @param list<string> $tokens  Token list, mutated in place.
     * @param list<string> $markers Marker words to look for.
     *
     * @return string The captured name following the first matching marker, or ''.
     */
    private static function extractMarked(array &$tokens, array $markers): string
    {
        $captured    = '';
        $result      = [];
        $found       = false;
        $consumeNext = false;

        foreach ($tokens as $token) {
            $isMarker = in_array(strtolower($token), $markers, true);

            if ($consumeNext) {
                // A second consecutive marker is dropped, not captured: stay in capture
                // mode so the real name following it becomes the captured value.
                if ($isMarker) {
                    continue;
                }

                $captured    = $token;
                $consumeNext = false;
                $found       = true;

                continue;
            }

            if ($isMarker) {
                $consumeNext = true;

                continue;
            }

            $result[] = $token;
        }

        $tokens = $result;

        return $found ? $captured : '';
    }
}
