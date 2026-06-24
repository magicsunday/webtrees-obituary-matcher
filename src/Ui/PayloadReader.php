<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use function is_array;
use function is_int;
use function is_string;

/**
 * The single, webtrees-free narrowing seam for the untrusted persisted match payload. A stored row is
 * reconstructed from on-disk JSON ({@see \MagicSunday\ObituaryMatcher\Matching\StoredMatch::fromArray()}
 * only asserts the payload is an array — no per-key validation), so it may be malformed-but-array
 * (hand-edited / an older schema). Both presentation screens (the worklist and the review screen) and
 * the confirm write gate read the payload through these helpers so they narrow it IDENTICALLY and
 * cannot drift — keeping the render gate and the write gate in lock-step.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class PayloadReader
{
    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Reads a key from a raw array as mixed, erasing any static shape so the per-field defensive
     * narrowing in the caller cannot be flagged as dead — the on-disk payload is reconstructed from
     * untrusted JSON and may be malformed-but-array.
     *
     * @param array<array-key, mixed> $source The raw array to read from.
     * @param string                  $key    The key to read.
     *
     * @return mixed The raw value, or null when the key is absent.
     */
    public static function read(array $source, string $key): mixed
    {
        return $source[$key] ?? null;
    }

    /**
     * Narrows a raw value to a string, defaulting to the given fallback when it is not a string.
     *
     * @param mixed  $value   The raw value.
     * @param string $default The fallback for a non-string value.
     *
     * @return string The narrowed string.
     */
    public static function asString(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Narrows a raw value to an int, defaulting to the given fallback when it is not an int.
     *
     * @param mixed $value   The raw value.
     * @param int   $default The fallback for a non-int value.
     *
     * @return int The narrowed int.
     */
    public static function asInt(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * Reads a nested string value from a two-level payload, narrowing defensively: the outer key must
     * hold an array and the inner key a string, otherwise null. Used where an extracted-facts sub-value
     * (for example the death date) is read from the untrusted on-disk payload.
     *
     * @param array<array-key, mixed> $source   The outer payload array.
     * @param string                  $outerKey The key holding the nested array.
     * @param string                  $innerKey The key holding the string inside the nested array.
     *
     * @return string|null The narrowed nested string, or null when either level is absent/mistyped.
     */
    public static function nestedString(array $source, string $outerKey, string $innerKey): ?string
    {
        $outer = self::read($source, $outerKey);

        if (!is_array($outer)) {
            return null;
        }

        $value = self::read($outer, $innerKey);

        return is_string($value) ? $value : null;
    }
}
