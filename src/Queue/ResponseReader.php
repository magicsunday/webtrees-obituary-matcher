<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Queue;

use InvalidArgumentException;
use MagicSunday\ObituaryMatcher\Domain\DeathNoticeRecord;
use RuntimeException;

/**
 * Reads the UNTRUSTED response.json a feeder writes into a job's state directory (done/<jobId>/ by
 * default, or the claimed ingesting/<jobId>/ when draining) off disk, then hands the decoded payload
 * to {@see ResponseValidator} for field-by-field narrowing into {@see DeathNoticeRecord}s. This reader
 * owns ONLY the two file-coupled steps — locating the job's state directory and reading the size-capped
 * JSON — so the validation core stays transport-agnostic and a future REST transport body validates
 * through the very same {@see ResponseValidator}. An IO failure surfaces as a plain RuntimeException
 * from the file helper; a content reject surfaces as {@see ResponseValidationException} from the
 * validator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ResponseReader
{
    /**
     * @var int The only response schema version this reader accepts. Mirrors {@see ResponseValidator::SCHEMA_VERSION}.
     */
    public const int SCHEMA_VERSION = ResponseValidator::SCHEMA_VERSION;

    /**
     * Constructor.
     *
     * @param QueuePaths        $paths     The queue path builder used to locate the job's state directory.
     * @param int               $maxBytes  The maximum accepted size of the response file in bytes.
     * @param ResponseValidator $validator The transport-agnostic validator the decoded payload is handed to.
     */
    public function __construct(
        private QueuePaths $paths,
        private int $maxBytes = QueueLimits::FEEDER_FILE_MAX_BYTES,
        private ResponseValidator $validator = new ResponseValidator(),
    ) {
    }

    /**
     * Reads, validates and decodes the response.json for the given job into death-notice records,
     * keyed by the personId they belong to.
     *
     * @param string       $jobId             The job whose response is read.
     * @param list<string> $expectedPersonIds The person ids that were in the request for this job
     *                                        (the job-ownership boundary; a result for any other id
     *                                        is rejected).
     * @param JobState     $fromState         The state directory the response is read from. Defaults to
     *                                        {@see JobState::Done} (the unclaimed done job); the module
     *                                        draining a claimed job passes {@see JobState::Ingesting}.
     *
     * @return array<string, list<DeathNoticeRecord>> The decoded notices keyed by personId.
     *
     * @throws InvalidArgumentException    When the jobId does not match the path-traversal guard.
     * @throws ResponseValidationException When the response fails any validation check.
     * @throws RuntimeException            When the underlying file read fails (a symlink, an
     *                                     unreadable, an oversize or a torn response.json).
     */
    public function read(string $jobId, array $expectedPersonIds, JobState $fromState = JobState::Done): array
    {
        // stateDir validates the jobId against the path-traversal guard (the same guard doneDir applied
        // before this read was generalised), so a hostile jobId can never escape the queue root.
        $path = $this->paths->stateDir($fromState, $jobId) . '/response.json';

        // AtomicFile already rejects symlinks, non-regular/unreadable files, oversize files and
        // decode errors; an IO failure surfaces as a plain RuntimeException, not a validation one.
        $data = AtomicFile::readJsonCapped($path, $this->maxBytes);

        return $this->validator->validate($data, $jobId, $expectedPersonIds);
    }
}
