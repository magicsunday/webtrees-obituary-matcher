<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use function in_array;
use function is_string;
use function mb_strtolower;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function substr;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Derives the canonical host of an obituary source URL: http(s)-only, lowercased, leading
 * `www.` stripped. A non-http(s) scheme, an unparseable URL, a host carrying control
 * characters, or a host that reduces to the empty string after the `www.` strip (e.g.
 * `https://www.`) all yield null, so every consumer can reject a bad host uniformly.
 *
 * This is the single source of host canonicalisation shared by the enqueue producer (which
 * lists the portals a candidate is already pending on) and {@see ObituaryWriteBack} (which
 * keys a per-portal source by host). It is pure: no webtrees coupling, no I/O.
 *
 * NOTE: the scheme gate lives HERE, not at the caller. The old inlined version in
 * {@see ObituaryWriteBack::canonicalHost()} relied on `writeConfirm` pre-checking `^https?://`
 * before calling it — but the enqueue producer calls this helper DIRECTLY, with no such
 * pre-check, so the helper itself must reject a non-http(s) scheme (else `ftp://example.test`
 * would wrongly yield `example.test`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class UrlHostNormalizer
{
    /**
     * Returns the canonical host for an http(s) URL, or null when the URL is non-http(s),
     * unparseable, or its host carries control characters.
     *
     * @param string $url The source notice URL.
     *
     * @return string|null The canonical host, or null when it cannot be derived.
     */
    public function canonicalHost(string $url): ?string
    {
        // Reject any input carrying a control character (C0 range or DEL): such a host could
        // break the JSON line it is later written into, or mislead the finder's consumption.
        // This guard runs against the RAW input because parse_url() — invoked first inside
        // normalizeForIdentity() — silently rewrites a control char in the host to `_`, so a
        // post-normalisation host check would never see it.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $normalized = UrlNormalizer::normalizeForIdentity($url);

        // Scheme gate: only http(s) URLs yield a host. A non-http(s) or schemeless URL is null,
        // so a finder hint never lists a host derived from an ftp:/mailto:/relative reference.
        $scheme = parse_url($normalized, PHP_URL_SCHEME);

        if (
            !is_string($scheme)
            || !in_array(mb_strtolower($scheme, 'UTF-8'), ['http', 'https'], true)
        ) {
            return null;
        }

        $host = parse_url($normalized, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        $host = mb_strtolower($host, 'UTF-8');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // A host that reduced to the empty string — `https://www.` strips to `""` — is not a usable
        // canonical host: returning `""` would leak an empty entry into the producer's excludedHosts
        // hint list (which only null-drops). Collapse it to null so every consumer rejects it
        // uniformly, the same contract as an unparseable or non-http(s) URL.
        if ($host === '') {
            return null;
        }

        return $host;
    }
}
