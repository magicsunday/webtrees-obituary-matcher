<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;

/**
 * Transport-neutral outcome of a finder job that completed successfully: the validated death notices
 * the finder returned, keyed by the requested person id. The REST transport produces this value object
 * through the {@see JobTransport} seam, so the ingest path stays oblivious to the transport.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CompletedJob
{
    /**
     * @param string                                 $jobId              The finder job identifier.
     * @param int                                    $treeId             The webtrees tree the job belongs to.
     * @param list<string>                           $requestedPersonIds The person ids the job requested.
     * @param array<string, list<DeathNoticeRecord>> $notices            The validated notices, keyed by requested person id.
     */
    public function __construct(
        public string $jobId,
        public int $treeId,
        public array $requestedPersonIds,
        public array $notices,
    ) {
    }
}
