<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

/**
 * The webtrees-free AND Queue-free readout of a finder capabilities probe, projected as a plain
 * carrier of scalar and list values only. The handler maps a probe result into this shape with
 * already-plain strings (Task 6); this VO imports nothing from the queue layer. The template escapes
 * every sink once with e() and maps the status key to its i18n label.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class ProbeReadoutView
{
    /**
     * Constructor.
     *
     * @param string                                                                        $statusKey      The probe-status key (`reachable`|`unreachable`|`invalid`|`not-applicable`); the template maps it to a label.
     * @param int|null                                                                      $httpStatus     The observed HTTP status code, or null when no response was received.
     * @param string|null                                                                   $finderId       The finder identifier reported by the probe, or null when unknown.
     * @param string|null                                                                   $finderVersion  The finder version reported by the probe, or null when unknown.
     * @param list<int>                                                                     $schemaVersions The supported schema versions reported by the probe.
     * @param list<array{id: string, name: string, country: string, regions: list<string>}> $portals        The advertised portals, each a plain-string record with its region list.
     * @param list<string>                                                                  $noticeFields   The notice fields the finder advertises.
     * @param array<string, bool>                                                           $features       The advertised feature flags keyed by feature name.
     */
    public function __construct(
        public string $statusKey,
        public ?int $httpStatus,
        public ?string $finderId,
        public ?string $finderVersion,
        public array $schemaVersions,
        public array $portals,
        public array $noticeFields,
        public array $features,
    ) {
    }
}
