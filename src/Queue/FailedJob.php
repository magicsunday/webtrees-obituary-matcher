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
 * Transport-neutral outcome of a finder job that did not complete: the requested persons plus a
 * {@see FailureCategory} that classifies why the job failed. The REST transport produces this value
 * object through the {@see JobTransport} seam, so the drain can react uniformly regardless of the
 * transport.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FailedJob
{
    /**
     * @param string          $jobId              The finder job identifier.
     * @param int             $treeId             The webtrees tree the job belongs to.
     * @param list<string>    $requestedPersonIds The person ids the job requested.
     * @param FailureCategory $reasonCategory     The category classifying the failure.
     */
    public function __construct(
        public string $jobId,
        public int $treeId,
        public array $requestedPersonIds,
        public FailureCategory $reasonCategory,
    ) {
    }
}
