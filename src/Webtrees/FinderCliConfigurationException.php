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
 * Signals an operator-facing finder-CLI configuration or misuse condition — the finder connection is not
 * configured / REST is not enabled, or an explicit `--rest-pending` path is invalid. Its message is a
 * fixed, secret-free operator hint that the CLI adapters echo to STDERR verbatim.
 *
 * It exists so the adapters can catch THIS narrow type for the safe-to-echo hints, and route every OTHER
 * {@see \Throwable} — notably a database error raised while reading the persisted preferences, whose
 * message could embed the SQL/DSN — to the guarded {@see HeadlessBootstrap::logCliError()} sink under a
 * fixed category instead of printing it to cron output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class FinderCliConfigurationException extends RuntimeException
{
}
