<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Support;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A bare PSR-18 client: it hands back queued responses and remembers what it was asked
 * for. Nothing here knows about Guzzle or any other client library, which is the point —
 * the core is supposed to work with any of them.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var list<RequestInterface> */
    public array $sent = [];

    public function willRespond(ResponseInterface|string $response, int $status = 200): self
    {
        $this->responses[] = is_string($response)
            ? new Response($status, ['Content-Type' => 'application/json'], $response)
            : $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = array_shift($this->responses);

        if ($response === null) {
            throw new LogicException(sprintf(
                'Unexpected real request: %s %s',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        $this->sent[] = $request;

        return $response;
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }
}
