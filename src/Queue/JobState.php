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
 * The four states a queued job moves through. A job is created in {@see JobState::Queued}, is
 * claimed into {@see JobState::Running}, and finishes in either {@see JobState::Done} or
 * {@see JobState::Failed}. The backing value of each case is the on-disk state directory name.
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
}
