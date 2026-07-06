<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Domain;

/**
 * One portal's coverage of one person in a finder response: which portal, whether it was searched
 * ({@see CoverageStatus}), how many notices it returned when searched, and an optional human-readable
 * message (e.g. the failure reason). A decoded, trusted projection of the contract's `PortalCoverage`
 * object; the untrusted narrowing happens in {@see \MagicSunday\ObituaryMatcher\Queue\ResponseValidator}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class PortalCoverage
{
    /**
     * Constructor.
     *
     * @param string         $portal      The portal identifier the finder searched.
     * @param CoverageStatus $status      Whether the portal was searched, failed, or skipped.
     * @param int|null       $noticeCount The number of notices the portal returned (only meaningful for
     *                                    {@see CoverageStatus::Ok}); null when the finder omitted it.
     * @param string|null    $message     An optional human-readable note (e.g. a failure reason).
     */
    public function __construct(
        public string $portal,
        public CoverageStatus $status,
        public ?int $noticeCount,
        public ?string $message,
    ) {
    }
}
