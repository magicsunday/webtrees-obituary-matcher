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
 * The immutable result of a finder capabilities probe. The constructor is private so the value object
 * can only be built through the four named constructors, which makes every illegal state
 * unrepresentable: a {@see ProbeStatus::Reachable} result always carries its {@see FinderCapabilities}
 * and never an HTTP status, only an {@see ProbeStatus::Unreachable} result may carry an HTTP status,
 * and neither an {@see ProbeStatus::Invalid} nor a {@see ProbeStatus::NotApplicable} result carries
 * either of the optional fields.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class CapabilitiesProbeResult
{
    /**
     * Constructor.
     *
     * @param ProbeStatus             $status       The probe outcome.
     * @param int|null                $httpStatus   The HTTP status of an unreachable probe, or null.
     * @param FinderCapabilities|null $capabilities The narrowed capabilities of a reachable probe, or null.
     */
    private function __construct(
        public ProbeStatus $status,
        public ?int $httpStatus,
        public ?FinderCapabilities $capabilities,
    ) {
    }

    /**
     * Builds the result of a probe that reached the finder and narrowed a valid capabilities document.
     *
     * @param FinderCapabilities $capabilities The narrowed capabilities the finder advertised.
     *
     * @return self The reachable result carrying the capabilities.
     */
    public static function reachable(FinderCapabilities $capabilities): self
    {
        return new self(ProbeStatus::Reachable, null, $capabilities);
    }

    /**
     * Builds the result of a probe that failed at the transport layer or returned a non-success status.
     *
     * @param int|null $httpStatus The non-success HTTP status, or null for a transport-layer failure.
     *
     * @return self The unreachable result carrying the optional HTTP status.
     */
    public static function unreachable(?int $httpStatus = null): self
    {
        return new self(ProbeStatus::Unreachable, $httpStatus, null);
    }

    /**
     * Builds the result of a probe whose body did not narrow to a valid capabilities document.
     *
     * @return self The invalid result.
     */
    public static function invalid(): self
    {
        return new self(ProbeStatus::Invalid, null, null);
    }

    /**
     * Builds the result of a probe that was not attempted because no finder is configured.
     *
     * @return self The not-applicable result.
     */
    public static function notApplicable(): self
    {
        return new self(ProbeStatus::NotApplicable, null, null);
    }
}
