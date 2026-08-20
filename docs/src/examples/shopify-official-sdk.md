# Shopify's Official PHP SDK

A real-world case study for the decorator-vs-middleware question from [Guzzle](../integrations/guzzle.md): which one does a given Guzzle-based SDK actually need? Verified against the actual source of `shopify/shopify-api` v6.1.1, not just its docs — worth being precise here, since it's easy to guess wrong.

## What the SDK actually does

`shopify/shopify-api` builds its own Guzzle client internally rather than accepting one through a constructor: every `Shopify\Clients\Rest`/`Graphql` call routes through `Context::$HTTP_CLIENT_FACTORY->client()`. On its own, that looks like the "SDK owns its own client" case that would normally need the middleware bridge.

But `Shopify\Clients\Http::request()` — the method every `Rest`/`Graphql` call ultimately goes through — calls the client with `$client->sendRequest($request)`. That's PSR-18's method, not Guzzle's native `request()`/`get()`. **The decorator is enough here — no middleware needed.**

## Wiring it up

`Context::$HTTP_CLIENT_FACTORY` is a public static property, and `HttpClientFactory` is a plain, non-final class with one method to override:

```php
use Psr\Http\Client\ClientInterface;
use Shopify\Clients\HttpClientFactory;

final class VcrHttpClientFactory extends HttpClientFactory
{
    public function __construct(private readonly ClientInterface $client) {}

    public function client(): ClientInterface
    {
        return $this->client;
    }
}
```

```php
use HttpVcr\VcrClient;
use Shopify\Context;
use Shopify\Clients\Rest;

Context::initialize(/* apiKey, apiSecretKey, scopes, hostName, sessionStorage, apiVersion, ... */);
// initialize() always sets up a plain HttpClientFactory internally — swap it right after:
Context::$HTTP_CLIENT_FACTORY = new VcrHttpClientFactory(
    new VcrClient(new GuzzleHttp\Client(), cassette: 'shopify/get-product'),
);

$client = new Rest($shop, $accessToken);
$response = $client->get(path: 'products'); // now goes through VcrClient::sendRequest()
```

`Context::initialize()` doesn't take a factory parameter — it always constructs a plain `HttpClientFactory` itself — so the swap happens by assigning the static property directly afterwards, not by passing anything into `initialize()`.

## The actual lesson

It's not "an SDK that builds its own client needs the middleware." It's "a client used only through `sendRequest()` is a decorator case, no matter who constructs it — as long as there's *some* seam to swap what gets returned." Guzzle used through its native API needs the middleware, because that bypasses `sendRequest()` regardless of who owns the client. Check which method the SDK actually calls before reaching for the middleware — don't assume from how the client gets built.
