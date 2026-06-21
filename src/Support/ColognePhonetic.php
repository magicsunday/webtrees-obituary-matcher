<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function in_array;
use function preg_replace;
use function str_contains;
use function str_split;
use function substr;

/**
 * Cologne phonetics (Kölner Phonetik, Postel 1969) — phonetic coding tuned for German names.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ColognePhonetic implements PhoneticEncoder
{
    /**
     * Encodes the given word using Cologne phonetics rules.
     *
     * @param string $value The word to encode.
     *
     * @return string The digit code with adjacent duplicates and non-leading zeros removed.
     */
    public function encode(string $value): string
    {
        // Reduce to canonical ASCII letters (ü->u etc.), strip non-alpha.
        $normalized = Normalizer::strip($value);
        $letters    = preg_replace('/[^a-z]/', '', $normalized) ?? '';
        $chars      = str_split($letters);

        $codes = [];

        foreach ($chars as $index => $char) {
            $next    = $chars[$index + 1] ?? '';
            $prev    = ($index > 0) ? $chars[$index - 1] : '';
            $codes[] = $this->codeFor($char, $prev, $next, $index === 0);
        }

        return $this->collapse($codes);
    }

    /**
     * Returns the single-digit code for the given letter in context.
     *
     * @param string $char    Current letter.
     * @param string $prev    Previous letter.
     * @param string $next    Next letter.
     * @param bool   $isFirst Whether this is the first letter of the word.
     *
     * @return string The single-digit code (or '-' to drop).
     */
    private function codeFor(string $char, string $prev, string $next, bool $isFirst): string
    {
        return match (true) {
            in_array($char, ['a', 'e', 'i', 'j', 'o', 'u', 'y'], true) => '0',
            ($char === 'h')                                            => '-',
            ($char === 'b')                                            => '1',
            ($char === 'p')                                            => ($next === 'h') ? '3' : '1',
            ($char === 'd' || $char === 't')                           => (($next !== '') && str_contains('csz', $next)) ? '8' : '2',
            in_array($char, ['f', 'v', 'w'], true)                     => '3',
            in_array($char, ['g', 'k', 'q'], true)                     => '4',
            ($char === 'c')                                            => $this->codeForC($prev, $next, $isFirst),
            ($char === 'x')                                            => (($prev !== '') && str_contains('ckq', $prev)) ? '8' : '48',
            ($char === 'l')                                            => '5',
            ($char === 'm' || $char === 'n')                           => '6',
            ($char === 'r')                                            => '7',
            ($char === 's' || $char === 'z')                           => '8',
            default                                                    => '-',
        };
    }

    /**
     * Returns the code for the letter C based on context.
     *
     * @param string $prev    Previous letter.
     * @param string $next    Next letter.
     * @param bool   $isFirst Whether the C is the first letter.
     *
     * @return string The code for the letter C.
     */
    private function codeForC(string $prev, string $next, bool $isFirst): string
    {
        if ($isFirst) {
            return (($next !== '') && str_contains('ahkloqrux', $next)) ? '4' : '8';
        }

        if (str_contains('sz', $prev)) {
            return '8';
        }

        return (($next !== '') && str_contains('ahkoqux', $next)) ? '4' : '8';
    }

    /**
     * Collapses per-letter codes: removes dashes, deduplicates adjacent digits, drops non-leading zeros.
     *
     * @param list<string> $codes Per-letter codes (digits, multi-digit for X, or '-').
     *
     * @return string The final code: duplicates collapsed, '-' removed, non-leading zeros removed.
     */
    private function collapse(array $codes): string
    {
        $joined = '';

        foreach ($codes as $code) {
            if ($code === '-') {
                continue;
            }

            $joined .= $code;
        }

        // Remove adjacent duplicate digits.
        $deduped = preg_replace('/(.)\1+/', '$1', $joined) ?? $joined;

        // Keep a leading zero, drop all other zeros.
        if ($deduped === '') {
            return '';
        }

        $first = $deduped[0];
        $tail  = substr($deduped, 1);
        $rest  = preg_replace('/0/', '', $tail) ?? $tail;

        return $first . $rest;
    }
}
