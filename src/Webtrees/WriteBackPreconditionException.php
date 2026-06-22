<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use RuntimeException;

/**
 * Thrown when a precondition of the GEDCOM write-back is not met: a source URL whose canonical
 * host carries a control character that would corrupt a GEDCOM line, or a portal-source record
 * that could not be created.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class WriteBackPreconditionException extends RuntimeException
{
}
