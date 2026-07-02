<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function chr;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function mb_strtolower;
use function parse_url;
use function preg_replace_callback;
use function sort;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function trim;

use const SORT_STRING;

/**
 * Single source of truth for the SOUR/notice idempotency key, and the matcher's implementation of the
 * published finder-contract URL-normalisation algorithm (the byte-for-byte spec lives in
 * `schemas/README.md`; {@see \MagicSunday\ObituaryMatcher\Test\Contract\UrlNormalisationContractTest}
 * pins the two together).
 *
 * Normalises a source URL so that two links pointing at the same notice collapse onto one key: the
 * scheme and host are lower-cased, the fragment is dropped, the default port is stripped, tracking query
 * parameters (utm_*, fbclid, gclid, mc_eid) are removed, the remaining query fields are
 * percent-normalised (RFC 3986 §6.2.2 — hex escapes upper-cased, unreserved escapes decoded) and sorted
 * in unsigned-byte order, and the percent-normalised path is kept verbatim. A non-parseable URL, or a
 * parseable one lacking a scheme or host (a relative path, a bare "host/path", a URN), yields the
 * trimmed, lower-cased input without throwing.
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
     * The RFC 3986 §2.3 "unreserved" characters. A percent-escape of any of these is DECODED during
     * normalisation (`%7E` → `~`), while every other escape is kept encoded, so two encodings of the same
     * URL that differ only in whether an unreserved byte is escaped collapse onto one identity key. Held as
     * a plain ASCII string so the per-byte membership test is a single-byte {@see str_contains()}.
     */
    private const string UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

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
            return mb_strtolower(trim($url), 'UTF-8');
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
            return mb_strtolower(trim($url), 'UTF-8');
        }

        // parse_url() yields no path for "https://example.test" (no trailing slash), which would not
        // collapse onto "https://example.test/" — two identity keys for the same root resource.
        // Default an empty path to the root slash so the two root forms share one key. The scheme and
        // host are already guaranteed non-empty by the guard above. Only the root collapses: a
        // non-root trailing slash ("/a" vs "/a/") stays distinct (they can be different resources).
        if ($path === '') {
            $path = '/';
        }

        // Percent-encoding normalisation (RFC 3986 §6.2.2) on the path, so two encodings of the same path
        // that differ only in escape hex case or in whether an unreserved byte is escaped share one key.
        // Dot-segments are deliberately NOT collapsed — the path is otherwise kept verbatim.
        $path = self::normalizePercentEncoding($path);

        // Keep an explicit non-default port in the key: a notice served on a non-default port is a
        // distinct resource from the same path on the default port, so the two must not collapse. A
        // port equal to the scheme's default (http->80, https->443) names the very same resource as
        // the portless URL, so it is stripped to keep both forms on one key. parse_url() returns the
        // port as an int, so the comparison against the default is an int compare. The port rides on
        // the host (so it only appears alongside a present scheme+host).
        $portNumber   = $parts['port'] ?? null;
        $defaultPorts = [
            'http'  => 80,
            'https' => 443,
        ];
        $schemeLower   = strtolower($scheme);
        $isDefaultPort = ($portNumber !== null)
            && isset($defaultPorts[$schemeLower])
            && ($portNumber === $defaultPorts[$schemeLower]);
        $port = (($portNumber !== null) && !$isDefaultPort) ? ':' . $portNumber : '';

        $result = $schemeLower . '://' . mb_strtolower($host, 'UTF-8') . $port . $path;

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
            // An empty pair (a "&&" run, or a leading/trailing "&") carries no name and is dropped.
            if ($pair === '') {
                continue;
            }

            // Percent-normalise the pair (RFC 3986 §6.2.2) BEFORE the tracking test, so a
            // percent-encoded tracking name ("%75tm_source" = "utm_source") is detected on the first pass
            // — testing the raw bytes first would strip it only on a second normalisation, breaking
            // idempotence. Normalisation never decodes an escaped "=" (%3D stays encoded), so the literal
            // "=" split below still finds the true name/value boundary.
            $normalizedPair = self::normalizePercentEncoding($pair);

            // The name is the bytes before the FIRST literal "=" (any further "=" stays in the value).
            [$name] = explode('=', $normalizedPair, 2);

            // Match the tracking-strip list case-insensitively (so "UTM_SOURCE", "Fbclid", "GCLID"
            // are stripped too), but keep the normalised pair bytes for a retained parameter: query
            // parameter names are case-sensitive in general, only the tracking-strip match folds case.
            $lowerName = strtolower($name);

            if (str_starts_with($lowerName, 'utm_')) {
                continue;
            }

            if (in_array($lowerName, self::TRACKING_PARAMS, true)) {
                continue;
            }

            $kept[] = $normalizedPair;
        }

        // Sort by the full byte string of each pair, ascending, with the explicit SORT_STRING flag: the
        // default SORT_REGULAR would compare two numeric-looking pairs numerically, so a byte-for-byte
        // pin-down demands the C-locale bytewise (memcmp) order SORT_STRING guarantees. The order is
        // case-sensitive and repeated pairs are preserved.
        sort($kept, SORT_STRING);

        return implode('&', $kept);
    }

    /**
     * Applies RFC 3986 §6.2.2 percent-encoding normalisation to a URL component: every `%XX` escape has
     * its two hex digits upper-cased, and an escape of an {@see UNRESERVED} character is DECODED to that
     * character; every other escape (a reserved or non-ASCII byte) is kept encoded. A literal `+` is left
     * verbatim — in a generic URI query it is a distinct byte from a space, so it is NOT folded to `%20`.
     * For a WELL-FORMED component — every `%` introducing a valid two-hex escape, which the contract's
     * valid-URL precondition guarantees — this is idempotent: each `%` is consumed as a self-contained
     * escape in the single left-to-right pass, a decoded unreserved byte is never itself a `%`, and a
     * reserved escape re-emits upper-cased unchanged, so a second pass is a no-op. A stray literal `%` that
     * is not a valid escape is left verbatim and lies outside that input domain — it is the only case where
     * a second pass could differ, as a later escape's decoded hex digit could complete the dangling `%`.
     *
     * @param string $value The raw URL component (a path, or a raw `name=value` query pair).
     *
     * @return string The percent-normalised component.
     */
    private static function normalizePercentEncoding(string $value): string
    {
        // Fast path: a component with no `%` has no escape to normalise, so it is returned unchanged
        // without spinning up the regex engine — the overwhelmingly common case (obituary URLs are
        // near-always escape-free), and byte-identical to running the pass (which would match nothing).
        if (!str_contains($value, '%')) {
            return $value;
        }

        $normalised = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $matches): string {
                // Two hex digits, so hexdec() is always in 0-255 (int); the cast satisfies chr()'s int
                // parameter (hexdec is typed int|float for the arbitrary-width case that cannot arise here).
                $decoded = chr((int) hexdec($matches[1]));

                // Decode only an unreserved byte; keep every other escape encoded, with upper-cased hex.
                if (str_contains(self::UNRESERVED, $decoded)) {
                    return $decoded;
                }

                return '%' . strtoupper($matches[1]);
            },
            $value
        );

        // preg_replace_callback returns null only on a PCRE engine error, which the static pattern above
        // cannot trigger; fall back to the input so the return type stays a definite string.
        return $normalised ?? $value;
    }
}
