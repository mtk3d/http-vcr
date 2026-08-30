<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\Symfony;

use Closure;
use HttpVcr\Config;
use HttpVcr\Psr17FactoryResolver;
use HttpVcr\VcrClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Record and replay for Symfony's native HTTP client (§3.10).
 *
 * `Psr18Client` needs nothing from this bridge — it is PSR-18 already. What does is
 * `Symfony\Contracts\HttpClient\HttpClientInterface`, the interface a Symfony application
 * autowires: a different signature, a richer response of its own, and no `sendRequest()`
 * anywhere in it.
 *
 * Nothing about record or replay lives here. The call is translated into a PSR-7 request,
 * handed to {@see VcrClient}, and the response translated back — into Symfony's own
 * `MockResponse`, which is precisely the shape of a response known in full before it is
 * returned, which is what a replayed one is.
 */
final class VcrHttpClient implements HttpClientInterface
{
    use HttpClientTrait;

    /**
     * The interface's own defaults, which is where `NativeHttpClient` and `CurlHttpClient`
     * both start. An empty array is not a smaller version of this: `prepareRequest()` reads
     * keys out of the merged options expecting them to be there, and before
     * symfony/http-client 7.3 it reads `base_uri` and `timeout` without guarding for
     * absence — so every request through a bridge starting from `[]` raises a PHP warning
     * on the lowest version this package supports.
     *
     * @var array<string, mixed>
     */
    private array $defaultOptions = HttpClientInterface::OPTIONS_DEFAULTS;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly UriFactoryInterface $uriFactory;

    /**
     * Not a constructor parameter of its own: a request body has to become a PSR-7 stream,
     * and the stream factory is one the core already resolves the same way — a third
     * argument here would only be a second place to say the same thing.
     */
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * A MockResponse describes a response; it does not behave as one until a client has
     * attached the request and the transfer info to it. So the bridge hands it back through
     * a MockHttpClient rather than directly, which is also what makes stream() free.
     */
    private readonly MockHttpClient $materializer;

    /**
     * The two factories are the bridge's own, not VcrClient's: building a request out of a
     * method, a URL and an options array is this class's job, and the core — handed its
     * requests ready-made — would carry two values it never uses (§3.10).
     */
    public function __construct(
        private readonly VcrClient $vcr,
        ?RequestFactoryInterface $requestFactory = null,
        ?UriFactoryInterface $uriFactory = null,
    ) {
        $factories = new Psr17FactoryResolver(array_filter([
            RequestFactoryInterface::class => $requestFactory,
            UriFactoryInterface::class => $uriFactory,
        ]) + Config::global()->psr17Factories());

        $this->requestFactory = $factories->requestFactory();
        $this->uriFactory = $factories->uriFactory();
        $this->streamFactory = $factories->streamFactory();

        // No base URI of its own: every URL reaching it has already been resolved against
        // whatever base_uri this client was configured with.
        $this->materializer = new MockHttpClient(null, null);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        /** @var array{0: array<string>, 1: array<string, mixed>} $prepared */
        $prepared = self::prepareRequest($method, $url, $options, $this->defaultOptions, true);

        [$parts, $options] = $prepared;
        $url = implode('', $parts);

        // The body is read once, here, and the string put back in its place: what the
        // cassette holds and what the response reports as its request are then the same.
        $options['body'] = $this->read($options['body'] ?? '');

        $response = $this->vcr->sendRequest($this->psrRequest($method, $url, $options));

        $this->materializer->setResponseFactory($this->replay($response));

        return $this->materializer->request($method, $url, $options);
    }

    /**
     * @param  ResponseInterface|iterable<array-key, ResponseInterface>  $responses
     */
    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->materializer->stream($responses, $timeout);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function psrRequest(string $method, string $url, array $options): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $this->uriFactory->createUri($url));

        /** @var list<string> $headers */
        $headers = $options['headers'] ?? [];

        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);

            $request = $request->withAddedHeader($name, ltrim($value, ' '));
        }

        $body = $options['body'] ?? '';

        return is_string($body) && $body !== ''
            ? $request->withBody($this->streamFactory->createStream($body))
            : $request;
    }

    private function replay(PsrResponse $response): MockResponse
    {
        return new MockResponse((string) $response->getBody(), [
            'http_code' => $response->getStatusCode(),
            'response_headers' => $response->getHeaders(),
        ]);
    }

    /**
     * A Symfony body is a string, a resource or a chunk-producing closure. Recording it
     * means having all of it, so the two lazy shapes are drained here.
     */
    private function read(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        if (! $body instanceof Closure) {
            return '';
        }

        $content = '';

        while (is_string($chunk = $body(self::$CHUNK_SIZE)) && $chunk !== '') {
            $content .= $chunk;
        }

        return $content;
    }
}
