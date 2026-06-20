<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function preg_replace;
use function strtolower;
use function strtr;
use function substr;
use function trim;

/**
 * Pure name/text normalisation. Avoids mbstring: folding is byte-safe strtr.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class Normalizer
{
    /**
     * @var array<string, string> Accented letters (both cases) folded to their base ASCII
     *                            letter, shared by both fold maps.
     *
     * Uppercase accented letters map to the lowercase base letter because the subsequent
     * byte-based strtolower() in clean() cannot lowercase multibyte characters.
     */
    private const array FOLD_ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u',
        'Ç' => 'c', 'Ñ' => 'n',
    ];

    /**
     * @var array<string, string> Diacritics folded to their canonical ASCII digraphs;
     *                            umlauts become ae/oe/ue, other accents drop to the base letter.
     */
    private const array FOLD_CANONICAL = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ...self::FOLD_ACCENTS,
    ];

    /**
     * @var array<string, string> Diacritics reduced to their base ASCII letter;
     *                            umlauts drop to the single base letter, ß to ss.
     */
    private const array FOLD_STRIP = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'Ä' => 'a', 'Ö' => 'o', 'Ü' => 'u',
        ...self::FOLD_ACCENTS,
    ];

    /**
     * @var list<string> Academic titles removed during cleaning.
     */
    private const array TITLES = ['dr.', 'prof.', 'ing.', 'pfarrer', 'dipl.'];

    /**
     * @var list<string> Name affixes (born/widow/known-as markers) removed during cleaning.
     */
    private const array AFFIXES = ['geb.', 'geborene', 'gebuertige', 'geburtige', 'verw.', 'verh.', 'genannt', 'gen.'];

    /**
     * @var list<string> Combined titles and affixes, precomputed to avoid a per-call array_merge.
     */
    private const array STRIP_WORDS = [...self::TITLES, ...self::AFFIXES];

    /**
     * Maximum number of input bytes processed; untrusted input is truncated to this length.
     */
    private const int MAX_INPUT_LENGTH = 512;

    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Returns the canonical lowercase key with diacritics folded to ae/oe/ue.
     *
     * @param string $value The raw value.
     *
     * @return string The canonical lowercase key (diacritics folded to ae/oe/ue).
     */
    public static function normalize(string $value): string
    {
        $bounded = substr(trim($value), 0, self::MAX_INPUT_LENGTH);

        return self::clean(strtr($bounded, self::FOLD_CANONICAL));
    }

    /**
     * Returns the lowercase key with diacritics AND their ASCII digraphs reduced
     * to the base letter, so Müller/Mueller/Muller all collapse to "muller".
     *
     * @param string $value The raw value.
     *
     * @return string The lowercase key with diacritics AND their ASCII digraphs reduced
     *                to the base letter, so Müller/Mueller/Muller all collapse to "muller".
     */
    public static function strip(string $value): string
    {
        $bounded = substr(trim($value), 0, self::MAX_INPUT_LENGTH);
        $cleaned = self::clean(strtr($bounded, self::FOLD_STRIP));

        return strtr($cleaned, ['ae' => 'a', 'oe' => 'o', 'ue' => 'u']);
    }

    /**
     * Returns the value lowercased, with titles/affixes removed and whitespace collapsed.
     *
     * @param string $value The folded value.
     *
     * @return string Lowercased, with titles/affixes removed and whitespace collapsed.
     */
    private static function clean(string $value): string
    {
        $lower = strtolower($value);

        foreach (self::STRIP_WORDS as $word) {
            $lower = strtr($lower, [
                ' ' . $word . ' ' => ' ',
                $word . ' '       => '',
                ' ' . $word       => '',
            ]);

            if ($lower === $word) {
                $lower = '';
            }
        }

        $collapsed = preg_replace('/\s+/', ' ', $lower);

        return trim($collapsed ?? $lower);
    }
}
