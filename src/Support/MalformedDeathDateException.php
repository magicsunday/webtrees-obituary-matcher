<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use RuntimeException;

/**
 * Thrown when a death date handed to {@see GedcomDateConverter::toGedcom()} is not an exact
 * `YYYY-MM-DD` calendar date (wrong shape, or a non-existent date such as 2023-02-31).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class MalformedDeathDateException extends RuntimeException
{
}
