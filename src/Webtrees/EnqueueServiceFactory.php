<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Webtrees;

use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use MagicSunday\ObituaryMatcher\Queue\FeederRequestReader;
use MagicSunday\ObituaryMatcher\Queue\QueueClient;
use MagicSunday\ObituaryMatcher\Queue\QueueLimits;
use MagicSunday\ObituaryMatcher\Queue\QueuePaths;
use MagicSunday\ObituaryMatcher\Support\FeederRequestFactory;
use MagicSunday\ObituaryMatcher\Support\QueryGenerator;
use MagicSunday\ObituaryMatcher\Support\UrlHostNormalizer;

/**
 * The single composition root for the producer object graph. Both the headless `tools/enqueue.php` CLI
 * adapter and the admin {@see ObituaryControlPanelHandler} trigger path assemble the very same 7-argument
 * {@see EnqueueService} graph over a queue root; this factory holds that wiring once so the two consumers
 * stay byte-identical and the response-size cap lives in a single named constant ({@see QueueLimits}).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class EnqueueServiceFactory
{
    /**
     * Static-only utility: no instances.
     */
    private function __construct()
    {
    }

    /**
     * Wires the full producer object graph over the given queue paths. The same {@see QueuePaths} instance
     * is reused for arg 1 and threaded into the {@see QueueClient}/{@see FeederRequestReader} so every
     * collaborator reads and writes the SAME queue root.
     *
     * @param QueuePaths $paths The queue path builder rooted at the resolved queue root.
     *
     * @return EnqueueService The wired enqueue producer.
     */
    public static function create(QueuePaths $paths): EnqueueService
    {
        return new EnqueueService(
            $paths,
            new QueueClient($paths),
            new FeederRequestReader($paths, QueueLimits::FEEDER_FILE_MAX_BYTES),
            new CandidateRepository(),
            new FeederRequestFactory(new QueryGenerator()),
            new UrlHostNormalizer(),
            new TreeService(new GedcomImportService()),
        );
    }
}
