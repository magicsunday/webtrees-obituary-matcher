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
 * The module's own headless-bootstrap failure. It carries only fixed, config-free messages, so the
 * shared {@see HeadlessBootstrap::bootForCli()} seam may echo its message to STDERR by construction —
 * a generic framework {@see RuntimeException} (whose message could embed a path or DSN) is a distinct
 * type and therefore falls through to the fixed-category arm instead.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class HeadlessBootstrapException extends RuntimeException
{
}
