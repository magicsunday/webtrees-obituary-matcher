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
 * The outcome of searching ONE portal for ONE person, per the finder↔matcher contract. It disambiguates
 * an empty result: `Ok` means the portal was searched and returned however many notices (0 is a genuine
 * miss); `Failed` means the portal errored (down/blocked) so its silence is NOT a confirmed miss; and
 * `Skipped` means the finder deliberately did not search it (e.g. a local negative cache). The matcher
 * needs this to tell "nothing found" from "portal down" and to avoid recording a false negative.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
enum CoverageStatus: string
{
    /**
     * The portal was searched successfully; its `noticeCount` is authoritative (0 = genuine miss).
     */
    case Ok = 'ok';

    /**
     * The portal errored (down, blocked, rate-limited); its silence is NOT a confirmed miss.
     */
    case Failed = 'failed';

    /**
     * The portal was deliberately not searched (e.g. a finder-side negative cache); no new information.
     */
    case Skipped = 'skipped';
}
