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
 * Thrown by the live re-check in {@see ObituaryWriteBack::writeConfirm()} when the individual already
 * carries a death date (DEAT/BURI/CREM) at the moment of the write. It closes the gate↔write race:
 * the confirm handler may have passed its own gate, but a concurrent edit could have added a death
 * date in between, so the writer refuses rather than creating a second, conflicting dated DEAT.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class DeathDateAlreadyPresentException extends RuntimeException
{
}
