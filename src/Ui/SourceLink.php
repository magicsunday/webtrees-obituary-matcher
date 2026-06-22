<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Ui;

use function is_string;
use function parse_url;
use function preg_match;

use const PHP_URL_HOST;

/**
 * The single source-URL safety decision shared by every view model: an HTTP(S) scheme allow-list
 * (so a hostile `javascript:` or `data:` scheme can never reach an anchor href) paired with the host
 * extraction. Each view model keeps its own field names on top of this value object; the scheme
 * guard and the host parse exist here exactly once.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class SourceLink
{
    /**
     * Constructor.
     *
     * @param string|null $href The HTTP(S) URL safe to use as an anchor href, or null when refused.
     * @param string|null $host The URL host for an HTTP(S) URL, or null when unavailable.
     */
    private function __construct(
        public ?string $href,
        public ?string $host,
    ) {
    }

    /**
     * Decides whether the given raw URL is a safe HTTP(S) link and extracts its host.
     *
     * @param string $url The source notice URL (raw, pre-normalisation).
     *
     * @return self The link decision: the http(s)-or-null href and the host-or-null.
     */
    public static function fromUrl(string $url): self
    {
        if (preg_match('~^https?://~i', $url) !== 1) {
            return new self(null, null);
        }

        $parsedHost = parse_url($url, PHP_URL_HOST);

        $host = (is_string($parsedHost) && ($parsedHost !== '')) ? $parsedHost : null;

        return new self($url, $host);
    }
}
