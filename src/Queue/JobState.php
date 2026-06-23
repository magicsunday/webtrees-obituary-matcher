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
 * The states a queued job moves through. A job is created in {@see JobState::Queued}, is claimed
 * into {@see JobState::Running} by the worker, and finishes a scrape in either {@see JobState::Done}
 * or {@see JobState::Failed}. The module then drains a done job: it claims it into
 * {@see JobState::Ingesting}, and the ingest finishes in either {@see JobState::Ingested} or
 * {@see JobState::FailedIngest}. The backing value of each case is the on-disk state directory name.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum JobState: string
{
    /**
     * The job has been enqueued and is awaiting a worker.
     */
    case Queued = 'queued';

    /**
     * The job has been claimed by a worker and is being processed.
     */
    case Running = 'running';

    /**
     * The job finished successfully.
     */
    case Done = 'done';

    /**
     * The job finished with an error.
     */
    case Failed = 'failed';

    /**
     * The module has claimed a done job and is ingesting its response into the tree.
     */
    case Ingesting = 'ingesting';

    /**
     * The module finished ingesting the job's response successfully.
     */
    case Ingested = 'ingested';

    /**
     * The module failed to ingest the job's response.
     */
    case FailedIngest = 'failed-ingest';
}
