# Quick Start

Say a class fetches a product from Shopify through a PSR-18 client:

```php
final class ShopifyClient
{
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requestFactory,
    ) {}

    public function getProduct(string $id): array
    {
        $request = $this->requestFactory->createRequest(
            'GET',
            "https://shop.myshopify.com/admin/api/2024-01/products/{$id}.json",
        );
        $response = $this->http->sendRequest($request);

        return json_decode((string) $response->getBody(), true);
    }
}
```

## One-time setup

Register the PHPUnit extension in `phpunit.xml`. PHPUnit has no auto-discovery for extensions, so this is the one line http-vcr can't add for you:

```xml
<extensions>
    <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
</extensions>
```

That's the whole setup. No config file, no bootstrap code, no cassette directory to create.

## Write the test

Put `#[UseCassette]` on the test and take the client from the trait — that's it:

```php
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;

final class ShopifyClientTest extends TestCase
{
    use InteractsWithCassettes;

    #[UseCassette('shopify/get-product')]
    public function testGetProduct(): void
    {
        $shopify = new ShopifyClient($this->vcrClient(), new GuzzleHttp\Psr7\HttpFactory());

        $product = $shopify->getProduct('123');

        $this->assertSame('T-Shirt', $product['title']);
    }
}
```

`$this->vcrClient()` is a `Psr\Http\Client\ClientInterface`, so it drops into the exact spot the real client occupied — no interface changes and no test-only branch in the code under test. The factory in the example is `ShopifyClient`'s own, for building requests; http-vcr never asks you for one.

## First run: record

On a developer machine, recording is allowed by default. With a real API key available, the call goes over the network and is written to `tests/Cassettes/shopify/get-product.json`:

```bash
SHOPIFY_API_KEY=sk_live_xxx vendor/bin/phpunit --filter testGetProduct
```

## Every run after: replay

The cassette exists now, so the default [`RecordIfAbsent`](../concepts/record-modes.md) mode replays it instead of recording again. The same test, with no network call, no API key, and no flakiness:

```bash
vendor/bin/phpunit --filter testGetProduct
```

Commit the cassette alongside the test. Any credentials that were in the `Authorization` or `Cookie` headers [were replaced with placeholders](../safety/redaction.md) before it hit disk.

## On CI: never record

CI is detected from the environment (`CI`, `CONTINUOUS_INTEGRATION`, `JENKINS_URL` and a couple more — the full list is in the [Environment Variables](../reference/environment.md) reference), and recording is refused there: a missing cassette fails the test loudly instead of quietly reaching for the real API without credentials.

That's a default, not a hard rule. An explicitly set `VCR_ALLOW_RECORDING=0` or `=1` always wins — set `0` in the pipeline if you'd rather not rely on detection at all, or `1` locally if something in your shell happens to set `CI`.

## Without PHPUnit

The attribute is a convenience over an ordinary object. Anywhere else — a script, a different test framework — construct it directly:

```php
use HttpVcr\VcrClient;

$vcr = new VcrClient(
    inner: new GuzzleHttp\Client(),
    cassette: 'shopify/get-product',
);

$shopify = new ShopifyClient($vcr, new GuzzleHttp\Psr7\HttpFactory());
```

Everything the attribute sets — record mode, matchers, redaction, strict mode — is a constructor argument or a method on this object. See the [VcrClient Reference](../reference/vcr-client.md).

## Where to go next

- [PHPUnit Integration](../integrations/phpunit.md) — the full attribute API, and refreshing one external API's recordings without touching the rest
- [Guzzle](../integrations/guzzle.md) — why code calling `$client->get()` needs the middleware rather than the decorator
- [Laravel](../integrations/laravel.md) — the `Http` facade, intercepted with no wiring in the test
