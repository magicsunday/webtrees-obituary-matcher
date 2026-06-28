<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Support;

use InvalidArgumentException;
use SensitiveParameter;

use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function strtolower;

use const PHP_URL_SCHEME;

/**
 * Immutable description of how the module talks to the obituary finder: either the file-drop queue
 * (`file`) or a REST endpoint (`rest`) with a base URL and an optional bearer token. The token is a
 * secret — it must never surface in an exception message, a log line, a built URL, a debug dump or
 * any other explicit string output. To uphold that, the constructor parameter and the `rest()`
 * factory mark the token `#[SensitiveParameter]` (so PHP redacts it in stack traces and backtraces),
 * {@see self::__debugInfo()} replaces it with `***` for `var_dump()`, and the class deliberately has
 * no `__toString()` so accidental string interpolation cannot spill it. Only the explicit
 * {@see self::token()} accessor hands the raw token back for its single legitimate use: the
 * `Authorization` header of the REST transport.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final readonly class FinderConnection
{
    /**
     * @param string      $transport The transport identifier, either `file` or `rest`.
     * @param string|null $baseUrl   The REST base URL, or null for the file transport.
     * @param string|null $token     The secret bearer token, or null when unauthenticated.
     */
    private function __construct(
        private string $transport,
        private ?string $baseUrl,
        #[SensitiveParameter]
        private ?string $token,
    ) {
    }

    /**
     * Creates a connection that drives the file-drop queue and carries no REST details.
     *
     * @return self The file-transport connection.
     */
    public static function file(): self
    {
        return new self('file', null, null);
    }

    /**
     * Creates a connection that drives the REST endpoint at the given base URL.
     *
     * @param string      $baseUrl The REST base URL the transport talks to.
     * @param string|null $token   The secret bearer token, or null when unauthenticated.
     *
     * @return self The REST-transport connection.
     *
     * @throws InvalidArgumentException When the base URL is not an http(s) URL or carries a control
     *                                  character, or when the token carries a control character. Either
     *                                  value flows into an outbound request line, so a control character
     *                                  would make a PSR-7 build throw (spilling a value into the throwing
     *                                  frame's arguments) or, on a non-validating client, inject a CRLF
     *                                  header — both rejected at this single source. The message never
     *                                  echoes the token.
     */
    public static function rest(string $baseUrl, #[SensitiveParameter] ?string $token): self
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            throw new InvalidArgumentException('The finder base URL must not contain control characters.');
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (
            !is_string($scheme)
            || !in_array(strtolower($scheme), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('The finder base URL must be an http or https URL.');
        }

        if (
            ($token !== null)
            && (preg_match('/[\x00-\x1F\x7F]/', $token) === 1)
        ) {
            throw new InvalidArgumentException('The finder bearer token must not contain control characters.');
        }

        return new self('rest', $baseUrl, $token);
    }

    /**
     * Returns the transport identifier (`file` or `rest`).
     *
     * @return string The transport identifier.
     */
    public function transport(): string
    {
        return $this->transport;
    }

    /**
     * Returns the REST base URL, or null for the file transport.
     *
     * @return string|null The REST base URL, or null.
     */
    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * Returns the raw bearer token for its single legitimate use (the Authorization header), or null
     * when the connection is unauthenticated or uses the file transport.
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
     * @return array{transport: string, baseUrl: string|null, token: string|null} The redacted shape.
     */
    public function __debugInfo(): array
    {
        return [
            'transport' => $this->transport,
            'baseUrl'   => $this->baseUrl,
            'token'     => $this->token === null ? null : '***',
        ];
    }
}
