# http-vcr — record and replay HTTP requests in PHP tests

**http-vcr** records real HTTP interactions the first time your tests run, and replays them on every run after that — so the test suite for "the code that talks to Shopify/Stripe/Zendesk" is fast, deterministic, and doesn't need network access or API credentials in CI.

It works by decorating a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (`Psr\Http\Client\ClientInterface`). Anything that already speaks PSR-18 — Guzzle 7+, Symfony's `Psr18Client`, php-http's clients — works with zero configuration. Recording and replay happen inside the `VcrClient` instance you construct, so there is no process-wide state to install or reset between tests.

```php
$vcr = new VcrClient($realClient, cassette: 'shopify/get-product');

$response = $vcr->sendRequest($request);
// first run: real request happens, response is recorded to tests/Cassettes/shopify/get-product.json
// every run after: no network call, the recorded response is replayed
```

That first run records without any extra setup on a developer machine, and refuses to record on CI — see [Record Modes](concepts/record-modes.md#when-recording-is-allowed-at-all) for how that's decided and how to override it in either direction.

## Where to go next

- [Installation](getting-started/installation.md) and your [first cassette](getting-started/quick-start.md)
- [How It Works](concepts/how-it-works.md) for the architecture
- [Record Modes](concepts/record-modes.md) for what happens during recording vs. playback
- [VcrClient Reference](reference/vcr-client.md) for every option in one table
