<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

/**
 * A single "repeat search needed" row on the worklist: a person whose last search left a portal outage
 * (§6.4 point 2 / §5.2c — a `PortalFailed` outcome), projected webtrees-free. Every field is a PLAIN,
 * untrusted string (never pre-escaped HTML); the worklist template escapes each sink once with e().
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class RetryRowView
{
    /**
     * Constructor.
     *
     * @param string $personName The tree individual's display name, reduced to plain text.
     * @param string $personId   The individual XREF.
     * @param string $personUrl  The internal individual-page URL (built by the handler).
     */
    public function __construct(
        public string $personName,
        public string $personId,
        public string $personUrl,
    ) {
    }
}
