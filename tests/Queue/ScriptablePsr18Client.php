<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Queue;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_shift;

/**
 * A scriptable PSR-18 client test double: each scripted callable is consumed in order and produces (or
 * throws) the next response, while every sent request is captured in the public {@see self::$sent} list
 * for assertions. A named class (rather than an anonymous one inside the trait) so PHPStan reliably reads
 * the scripted-responder list type when the trait is analysed in each using class's context.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
final class ScriptablePsr18Client implements ClientInterface
{
    /**
     * @var list<RequestInterface> The requests the caller sent, in order.
     */
    public array $sent = [];

    /**
     * @var list<callable(RequestInterface): ResponseInterface> The scripted responders, in order.
     */
    private array $script;

    /**
     * Constructor.
     *
     * @param list<callable(RequestInterface): ResponseInterface> $script The scripted responders, in order.
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $next         = array_shift($this->script);

        if ($next === null) {
            throw new RuntimeException('Unexpected request: ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return $next($request);
    }
}
