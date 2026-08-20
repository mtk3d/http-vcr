# Other PSR-18 Clients

## Works with zero setup

Anything implementing `Psr\Http\Client\ClientInterface` and used purely through that interface:

| Client | Notes |
|---|---|
| `GuzzleHttp\Client` (Guzzle 7+) | only for calls made through `sendRequest()` — see [Guzzle](guzzle.md) for why that matters |
| `Symfony\Component\HttpClient\Psr18Client` | no caveats — see [Symfony](symfony.md) |
| `Http\Client\Curl\Client`, `Http\Client\Socket\Client` (php-http) | native PSR-18 |
| `Buzz\Client\*` (kriswallsmith/buzz) | native PSR-18 |

```php
$vcr = new VcrClient(new Http\Client\Curl\Client(), cassette: 'shopify/get-product');
```

## Deliberately out of scope

- **Amp (`amphp/http-client`), ReactPHP (`react/http`)** — a different paradigm entirely: event-loop based, their own promise implementations, not PSR-18's synchronous `sendRequest()`. Doesn't fit the decorator architecture. A possible separate project, not part of this one.
- **Raw `curl_exec` / stream contexts** — this is precisely the problem http-vcr exists to avoid (see [Introduction](../introduction.md)). If a codebase calls `curl_exec` directly, there's no PSR-18 boundary to decorate — the fix is to introduce one (a PSR-18 client), not to add another monkey-patching layer on top of curl.
