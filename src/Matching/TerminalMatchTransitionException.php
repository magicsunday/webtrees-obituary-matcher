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
 * Signals that an explicit lifecycle transition was refused because the existing stored row is in
 * the terminal Confirmed state. An automated re-ingest treats a terminal row as a silent no-op, but
 * an explicit caller action (such as a reviewer rejecting a match) must surface the refusal so the
 * caller can react (for example, prompt the user to un-confirm the match first) rather than have the
 * operation vanish without a trace.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class TerminalMatchTransitionException extends RuntimeException
{
}
