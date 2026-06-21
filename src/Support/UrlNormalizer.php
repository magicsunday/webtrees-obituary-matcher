<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function explode;
use function implode;
use function in_array;
use function is_array;
use function parse_url;
use function sort;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Single source of truth for the SOUR/notice idempotency key.
 *
 * Normalises a source URL so that two links pointing at the same notice collapse onto one key:
 * the scheme and host are lower-cased, the fragment is dropped, tracking query parameters (utm_*, fbclid,
 * gclid, mc_eid) are stripped, and the remaining path plus the sorted residual query are kept.
 * A non-parseable URL, or a parseable one lacking a scheme or host (a relative path, a bare
 * "host/path", a URN), yields the trimmed, lower-cased input without throwing.
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

        // A scheme-less or host-less input (a relative path, a bare "host/path", a URN) has no
        // canonical absolute-URL identity: rebuilding "scheme://host" would yield a malformed
        // "://..." key. Fall back to the documented no-throw self-key instead.
        if (
            ($scheme === '')
            || ($host === '')
        ) {
            return strtolower(trim($url));
        }

        $result = strtolower($scheme) . '://' . strtolower($host) . $path;

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
     * The raw "name=value" pairs are handled byte-faithfully (no urldecode of the name) so a dot or
     * space in a parameter name survives — parse_str() would mangle those to an underscore and so
     * collapse distinct URLs onto one identity key. Sorting the surviving raw pair strings keeps the
     * result order-deterministic.
     *
     * @param string $query The raw query string (without the leading question mark).
     *
     * @return string The rebuilt query string, or an empty string when nothing remains.
     */
    private static function residualQuery(string $query): string
    {
        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name] = explode('=', $pair, 2);

            if (str_starts_with($name, 'utm_')) {
                continue;
            }

            if (in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }

            $kept[] = $pair;
        }

        sort($kept);

        return implode('&', $kept);
    }
}
