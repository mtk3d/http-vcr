# Symfony HttpClient

Symfony's HTTP client has two faces, and only one of them is PSR-18.

## `Psr18Client` — works with zero setup

```php
use Symfony\Component\HttpClient\Psr18Client;

$vcr = new VcrClient(new Psr18Client(), cassette: 'shopify/get-product');
```

`Psr18Client` implements `Psr\Http\Client\ClientInterface` directly and has no parallel native API bypassing it — unlike Guzzle, there's nothing to route around here (see [Guzzle](guzzle.md) for that problem). Anything typed against PSR-18 works without any bridge.

## The native `HttpClientInterface` — needs the bridge

`Symfony\Contracts\HttpClient\HttpClientInterface` is Symfony's idiomatic way of making HTTP calls — autowiring it in services is the norm in a Symfony app, not the exception — but it is **not** PSR-18. Different signature:

```php
interface HttpClientInterface {
    public function request(string $method, string $url, array $options = []): ResponseInterface;
    public function stream($responses, ?float $timeout = null): ResponseStreamInterface;
    public function withOptions(array $options): static;
}
```

with its own, richer `ResponseInterface` (streaming, `getInfo()`, chunked reads). `VcrHttpClient` bridges this:

```php
use HttpVcr\Bridge\Symfony\VcrHttpClient;

$client = new VcrHttpClient($vcr); // implements Symfony's HttpClientInterface
```

```php
public function __construct(
    VcrClient $vcr,
    ?RequestFactoryInterface $requestFactory = null,
    ?UriFactoryInterface $uriFactory = null,
) {}
```

Internally, `request()` builds a PSR-7 request from `$method`/`$url`/`$options` via PSR-17 factories. This bridge is the one place that needs `RequestFactoryInterface` and `UriFactoryInterface` on top of the response/stream factories the core uses — which is why they're arguments here rather than on `VcrClient`, whose own constructor would otherwise carry two values it never uses and would have to expose getters for. Left as `null`, they're resolved the [same way](../reference/vcr-client.md#psr-17-factories) as every other PSR-17 factory — as is the stream factory the bridge puts a request body on, which has no argument of its own because there'd be nothing to say with it that `Config` doesn't already say. It then sends the request through the wrapped `VcrClient` and maps the PSR-7 response back onto Symfony's response shape — using Symfony's own `Symfony\Component\HttpClient\Response\MockResponse` for the return value, since a replayed cassette response is by definition a complete, known-in-advance response, which is exactly what `MockResponse` is built for.

A `MockResponse` on its own is a *description* of a response, not a usable one — it has to be materialized by a client that attaches the request and transfer info to it. So the bridge keeps a `MockHttpClient` internally and returns what *that* produces, rather than handing back the `MockResponse` directly; otherwise `getInfo()` and friends behave unpredictably. `stream()` delegates to the same `MockHttpClient`.

The interface has a third method, `withOptions()`, which the bridge implements the way Symfony's own clients do: it returns a new `VcrHttpClient` with the default options merged in.

Like the Guzzle bridge, this doesn't duplicate any record/replay logic — it only translates the shape of the call. All of the actual behavior lives in `VcrClient`.

A service autowiring `HttpClientInterface`, tested through the bridge, is in [`examples/symfony-http-client.php`](https://github.com/mtk3d/http-vcr/blob/master/examples/symfony-http-client.php).
