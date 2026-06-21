<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Matching;

use RuntimeException;

/**
 * Signals that a stored match row could not be reconstructed because it is malformed (a missing or
 * mistyped key, an unknown status). The single-key read paths let this propagate fail-loud, while a
 * directory scan catches it per row and skips only the poison row so the remaining valid rows still
 * surface rather than the whole scan aborting.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class CorruptMatchRowException extends RuntimeException
{
}
