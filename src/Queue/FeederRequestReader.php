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
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;

use function is_array;
use function is_int;
use function is_string;

/**
 * Reads and validates the request.json of a CLAIMED job, narrowing the payload down to the numeric
 * tree id and the list of requested person ids the queue-drain needs. The job's own request is the
 * trusted side of the queue (this module wrote it), but the reader still narrows every consumed
 * field with an is_* guard so a corrupt or hand-edited file is rejected with a dedicated
 * {@see ResponseValidationException} rather than crashing the drain.
 *
 * Paths are built from the VALIDATED directory {@see $jobId} the caller passes, never from the
 * untrusted JSON "jobId"; the embedded jobId is only compared against it as a consistency gate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FeederRequestReader
{
    /**
     * Constructor.
     *
     * @param QueuePaths $paths    The queue path builder used to locate the job's state directory.
     * @param int        $maxBytes The maximum accepted size of the request file in bytes.
     */
    public function __construct(
        private QueuePaths $paths,
        private int $maxBytes,
    ) {
    }

    /**
     * Reads, validates and narrows the request.json for the given job into its tree id and the list
     * of requested person ids.
     *
     * Trust boundary: request.json is the MODULE-written instruction (the trusted side of the queue
     * contract), so its `treeId` selects the target tree. A feeder reads request.json and writes only
     * response.json; it MUST NOT mutate request.json. The reader narrows every consumed field
     * defensively, but does NOT re-derive the tree from a module-side source — pinning `treeId`
     * against the enqueued value is the enqueue slice's concern, out of scope here.
     *
     * @param string   $jobId     The validated job identifier whose request is read (also used to
     *                            build the path; the embedded JSON jobId is only a consistency gate).
     * @param JobState $fromState The state directory the claimed request lives in (defaults to
     *                            {@see JobState::Done}; the drain passes {@see JobState::Ingesting}).
     *
     * @return array{treeId: int, requestedPersonIds: list<string>} The narrowed request fields.
     *
     * @throws InvalidArgumentException    When the jobId does not match the path-traversal guard.
     * @throws ResponseValidationException When the request fails any validation check.
     */
    public function read(string $jobId, JobState $fromState = JobState::Done): array
    {
        $path = $this->paths->stateDir($fromState, $jobId) . '/request.json';

        // AtomicFile already rejects symlinks, non-regular/unreadable files, oversize files and
        // decode errors; an IO failure surfaces as a plain RuntimeException, not a validation one.
        $data = AtomicFile::readJsonCapped($path, $this->maxBytes);

        if (($data['schemaVersion'] ?? null) !== FeederRequestFactory::SCHEMA_VERSION) {
            throw new ResponseValidationException('Unknown or missing request schema version.');
        }

        if (($data['jobId'] ?? null) !== $jobId) {
            throw new ResponseValidationException('Request jobId does not match the claimed job.');
        }

        $treeId = $data['treeId'] ?? null;

        if (!is_int($treeId)) {
            throw new ResponseValidationException('Request treeId is missing or not an integer.');
        }

        $candidates = $data['candidates'] ?? null;

        if (!is_array($candidates)) {
            throw new ResponseValidationException('Request candidates is missing or not a list.');
        }

        $requestedPersonIds = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                throw new ResponseValidationException('Request candidate is not an object.');
            }

            $personId = $candidate['personId'] ?? null;

            if (
                !is_string($personId)
                || ($personId === '')
            ) {
                throw new ResponseValidationException(
                    'Request candidate personId is missing, not a string or empty.'
                );
            }

            $requestedPersonIds[] = $personId;
        }

        return [
            'treeId'             => $treeId,
            'requestedPersonIds' => $requestedPersonIds,
        ];
    }
}
