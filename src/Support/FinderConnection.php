<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use SensitiveParameter;

use function in_array;
use function is_string;
use function parse_url;
use function rtrim;
use function strtolower;

/**
 * Immutable description of how the module talks to the obituary finder: a REST endpoint with a base URL
 * and an optional bearer token. The token is a secret — it must never surface in an exception message, a
 * log line, a built URL, a debug dump or any other explicit string output. To uphold that, the
 * constructor parameter and the `rest()` factory mark the token `#[SensitiveParameter]` (so PHP redacts
 * it in stack traces and backtraces), {@see self::__debugInfo()} replaces it with `***` for
 * `var_dump()`, and the class deliberately has no `__toString()` so accidental string interpolation
 * cannot spill it. Only the explicit {@see self::token()} accessor hands the raw token back for its
 * single legitimate use: the `Authorization` header of the REST transport.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderConnection
{
    /**
     * @param string      $baseUrl The REST base URL the transport talks to.
     * @param string|null $token   The secret bearer token, or null when unauthenticated.
     */
    private function __construct(
        private string $baseUrl,
        #[SensitiveParameter]
        private ?string $token,
    ) {
    }

    /**
     * Creates a connection that drives the REST endpoint at the given base URL.
     *
     * @param string      $baseUrl The REST base URL the transport talks to.
     * @param string|null $token   The secret bearer token, or null when unauthenticated.
     *
     * @return self The REST-transport connection.
     *
     * @throws InvalidArgumentException When the base URL is not an http(s) URL, has no host, carries a
     *                                  control character, embeds credentials (a userinfo component) or is
     *                                  not a buildable PSR-7 URI (an RFC-illegal host parse_url tolerates
     *                                  but the request build rejects), or when the token carries a control
     *                                  character. The base URL and token
     *                                  both flow into an outbound request line, so a control character
     *                                  would make a PSR-7 build throw (spilling a value into the throwing
     *                                  frame's arguments) or, on a non-validating client, inject a CRLF
     *                                  header. A userinfo component embeds a secret in the base URL, which
     *                                  is echoed into transport error messages — credentials belong only
     *                                  in the bearer header. The host requirement also rejects the
     *                                  scheme-opaque `https:user:pass@host` form (no authority), where the
     *                                  credentials hide in the path and the userinfo check alone misses
     *                                  them. All rejected at this single source; the message never echoes
     *                                  the token or the credentials.
     */
    public static function rest(string $baseUrl, #[SensitiveParameter] ?string $token): self
    {
        if (ControlChars::contains($baseUrl)) {
            throw new InvalidArgumentException('The finder base URL must not contain control characters.');
        }

        $parts = parse_url($baseUrl);

        if ($parts === false) {
            throw new InvalidArgumentException('The finder base URL must be an http or https URL.');
        }

        $scheme = $parts['scheme'] ?? null;

        if (
            !is_string($scheme)
            || !in_array(strtolower($scheme), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('The finder base URL must be an http or https URL.');
        }

        // A valid REST base URL must carry an explicit authority (host). Requiring it also rejects the
        // scheme-opaque `https:user:pass@host` form (no `//`), where parse_url puts `user:pass@host` in
        // the path with no host and no user/pass keys — a userinfo-smuggling gap the user/pass check
        // alone would miss, letting the embedded credentials reach a transport error message.
        $host = $parts['host'] ?? null;

        if (
            !is_string($host)
            || ($host === '')
        ) {
            throw new InvalidArgumentException('The finder base URL must include a host.');
        }

        // Credentials must travel only in the Authorization header. A userinfo component
        // (https://user:pass@host) embeds a secret in the base URL, which is echoed verbatim into a
        // transport error message (and thence a log line or cron mail) — reject it at the source so the
        // canonical credentials-in-URL anti-pattern never reaches the error path.
        if (
            isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('The finder base URL must not embed credentials.');
        }

        if (
            ($token !== null)
            && ControlChars::contains($token)
        ) {
            throw new InvalidArgumentException('The finder bearer token must not contain control characters.');
        }

        // parse_url is lenient: it accepts an RFC-illegal host such as `ho st` (an embedded space), so the
        // checks above pass. The probe and the live transport, however, build their request URL as the base
        // plus an endpoint path and feed it to the PSR-7 factory, whose stricter reparse then throws a
        // MalformedUriException — an InvalidArgumentException that is NOT a ClientExceptionInterface, so it
        // escapes the probe's catch and crashes the live drain once the connection is persisted. Validate
        // the URL through the same PSR-7 builder here (over a representative endpoint path, mirroring how
        // every consumer assembles it) so an unbuildable base URL is rejected at this single source and no
        // consumer's request build can throw. The build is faithful by construction — no hand-rolled host
        // regex that would diverge from what Guzzle actually accepts (it tolerates `_`, `|`, IDN hosts).
        try {
            new Uri(rtrim($baseUrl, '/') . '/capabilities');
        } catch (MalformedUriException $exception) {
            throw new InvalidArgumentException('The finder base URL is not a valid URI.', 0, $exception);
        }

        return new self($baseUrl, $token);
    }

    /**
     * Returns the REST base URL the transport talks to.
     *
     * @return string The REST base URL.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Returns the canonical identity key of the base URL: the base URL with any single trailing slash
     * stripped. This is the SINGLE source of the dedup/ledger-identity rule — `https://f.example` and
     * `https://f.example/` are the same endpoint, so they must resolve to the same key. Both the
     * connection de-duplication ({@see FinderConnectionResolver::listFromConfig()}) and the per-finder
     * ledger-root namespacing ({@see \MagicSunday\ObituaryMatcher\Webtrees\RestCliBootstrap::resolveAll()})
     * key on this method, so the two can never drift: if the rule ever changed, both sites change together.
     *
     * @return string The base URL with any trailing slash removed.
     */
    public function baseUrlKey(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Returns the raw bearer token for its single legitimate use (the Authorization header), or null
     * when the connection is unauthenticated.
     *
     * @return string|null The secret bearer token, or null.
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * Returns the redacted debug representation used by `var_dump()`. The token is replaced with
     * `***` (keeping the null/non-null distinction) so a debug dump never leaks the secret.
     *
     * @return array{baseUrl: string, token: string|null} The redacted shape.
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'token'   => $this->token === null ? null : '***',
        ];
    }
}
