<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function preg_match;

/**
 * Predicate for ASCII control characters — the C0 range U+0000–U+001F plus DEL (U+007F). The identical
 * guard was previously inlined at every URL / host / token / GEDCOM free-text validation site; this
 * centralises it so the rule has a single source of truth.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ControlChars
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Returns whether the subject contains any ASCII control character (a C0 control or DEL).
     *
     * @param string $subject The string to test.
     *
     * @return bool True when at least one control character is present.
     */
    public static function contains(string $subject): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $subject) === 1;
    }
}
