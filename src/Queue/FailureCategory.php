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
 * The category classifying why a finder job did not complete. Replaces the free-form snake_case string
 * literals that were scattered across the transport and the drain with a single source of truth, so a
 * divergent spelling becomes a compile-time impossibility rather than a latent bug. The backing value is
 * the wire/persistence form (snake_case), reached via {@see self::value} only at a string/JSON boundary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum FailureCategory: string
{
    /**
     * The remote reported no such job for a GET (a 404) — the finder never ran the posted job.
     */
    case FinderJobMissing = 'finder_job_missing';

    /**
     * The finder's response was structurally non-conforming (a permanent body fault, a missing/non-string
     * `state`, or a body that failed contract validation) and the contract reproduces it on every re-GET.
     */
    case ResponseInvalid = 'response_invalid';

    /**
     * The finder itself reported the job as failed (a `state` of `failed`).
     */
    case FinderFailed = 'finder_failed';

    /**
     * The job targets a tree this webtrees instance does not know (an unknown tree id at ingest time).
     */
    case TreeUnknown = 'tree_unknown';

    /**
     * Persisting the finder's matches failed mid-flight (a disk-full or permission error); the remote
     * keeps the job so the result stays recoverable.
     */
    case IngestFailed = 'ingest_failed';
}
