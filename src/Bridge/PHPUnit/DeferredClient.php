<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The real client, resolved the first time something actually needs it.
 *
 * The bridge builds a VcrClient for every test carrying `#[UseCassette]`, and most of
 * those tests replay and never make a request. Resolving the transport up front would
 * turn "no HTTP client installed" into an error for a suite that was never going to send
 * anything (§3.14).
 *
 * @internal
 */
final class DeferredClient implements ClientInterface
{
    private ?ClientInterface $client = null;

    /**
     * @param Closure(): ClientInterface $resolve
     */
    public function __construct(private readonly Closure $resolve)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return ($this->client ??= ($this->resolve)())->sendRequest($request);
    }
}
