<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

/**
 * The outcome of a finder capabilities probe. A probe is {@see ProbeStatus::Reachable} when the
 * finder answered with a document that narrowed to a valid {@see FinderCapabilities},
 * {@see ProbeStatus::Unreachable} when the request failed at the transport layer (a connection error
 * or a non-success HTTP status), {@see ProbeStatus::Invalid} when the finder answered but the body
 * did not narrow to a valid capabilities document, and {@see ProbeStatus::NotApplicable} when no
 * finder is configured so no probe was attempted.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum ProbeStatus
{
    /**
     * The finder answered with a valid capabilities document.
     */
    case Reachable;

    /**
     * The probe request failed at the transport layer or returned a non-success HTTP status.
     */
    case Unreachable;

    /**
     * The finder answered but the body did not narrow to a valid capabilities document.
     */
    case Invalid;

    /**
     * No finder is configured, so no probe was attempted.
     */
    case NotApplicable;
}
