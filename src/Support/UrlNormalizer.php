<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function array_keys;
use function http_build_query;
use function in_array;
use function is_array;
use function ksort;
use function parse_str;
use function parse_url;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Single source of truth for the SOUR/notice idempotency key.
 *
 * Normalises a source URL so that two links pointing at the same notice collapse onto one key:
 * the host is lower-cased, the fragment is dropped, tracking query parameters (utm_*, fbclid,
 * gclid, mc_eid) are stripped, and the remaining path plus the sorted residual query are kept.
 * A non-parseable URL yields the trimmed, lower-cased input without throwing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class UrlNormalizer
{
    /**
     * The tracking query parameters dropped during normalisation (in addition to the utm_* prefix).
     *
     * @var list<string>
     */
    private const array TRACKING_PARAMS = [
        'fbclid',
        'gclid',
        'mc_eid',
    ];

    /**
     * Static-only utility; no instances.
     */
    private function __construct()
    {
    }

    /**
     * Derives the idempotency key for a source URL.
     *
     * @param string $url The source URL to normalise.
     *
     * @return string The normalised identity key, or the trimmed lower-cased input when unparseable.
     */
    public static function normalizeForIdentity(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return strtolower(trim($url));
        }

        $scheme = $parts['scheme'] ?? '';
        $host   = $parts['host'] ?? '';
        $path   = $parts['path'] ?? '';
        $query  = $parts['query'] ?? '';

        $result = $scheme . '://' . strtolower($host) . $path;

        if ($query !== '') {
            $residual = self::residualQuery($query);

            if ($residual !== '') {
                $result .= '?' . $residual;
            }
        }

        return $result;
    }

    /**
     * Strips the tracking parameters from a raw query string and rebuilds the sorted remainder.
     *
     * @param string $query The raw query string (without the leading question mark).
     *
     * @return string The rebuilt query string, or an empty string when nothing remains.
     */
    private static function residualQuery(string $query): string
    {
        /** @var array<string, mixed> $params */
        $params = [];
        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            $name = (string) $key;

            if (
                str_starts_with($name, 'utm_')
                || in_array($name, self::TRACKING_PARAMS, true)
            ) {
                unset($params[$key]);
            }
        }

        ksort($params);

        return http_build_query($params);
    }
}
