# Introduction

**http-vcr** records real HTTP interactions the first time your tests run, and replays them on every run after that — so the test suite for "the code that talks to Shopify/Stripe/Zendesk" is fast, deterministic, and doesn't need network access or API credentials in CI.

It works by decorating a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (`Psr\Http\Client\ClientInterface`). Anything that already speaks PSR-18 — Guzzle 7+, Symfony's `Psr18Client`, php-http's clients — works with zero configuration. No monkey-patching curl, no swapping stream wrappers, no global state that breaks the moment your HTTP client library changes its internals.

```php
$vcr = new VcrClient($realClient, cassette: 'shopify/get-product');

$response = $vcr->sendRequest($request);
// first run: real request happens, response is recorded to tests/Cassettes/shopify/get-product.json
// every run after: no network call, the recorded response is replayed
```

That first run records without any extra setup on a developer machine, and refuses to record on CI — see [Record Modes](concepts/record-modes.md#when-recording-is-allowed-at-all) for how that's decided and how to override it in either direction.

## Why not php-vcr or php-http/vcr-plugin?

| | php-vcr | php-http/vcr-plugin | **http-vcr** |
|---|---|---|---|
| Hook point | curl/stream/SOAP (monkey-patch) | `PluginClient` from httplug | decorator over `Psr\Http\Client\ClientInterface` |
| Ecosystem dependency | none (but fragile) | `php-http/httplug` | none on the request path — PSR interfaces only[^deps] |
| Sensitive data redaction | none (breaks matching) | none | bidirectional filter (redact/unredact), request **and** response, with a few headers redacted by default |
| JSON matching | none (raw string) | none (hashes the raw body) | semantic `body_json` matcher |
| Matchers | callable by name | naming strategy | composable `RequestMatcherInterface` objects |
| Strict/sequential mode | open issue since 2016 | none | built in from day one |
| Auto re-record | none | none | `staleAfter`, informational by default |
| Protecting mutating requests from re-recording | none | none | locked interactions |
| Refreshing one API's recordings when a test uses several | none (the file is the unit) | none | `[cassette]@[provider]` selector — the interaction is the unit, identified by request host |

[^deps]: The record/replay core — everything a request actually passes through — depends only on `psr/http-message`, `psr/http-client`, and `psr/http-factory`. The package additionally requires `symfony/console` and `nikic/php-parser` for the `http-vcr` CLI; since http-vcr is installed as a dev dependency, neither ends up in an application's production autoloader.

The short version: `php-vcr` hooks curl/streams globally, which is fragile across Guzzle/curl version changes. `php-http/vcr-plugin` requires buying into the httplug `PluginClient` stack. http-vcr targets PSR-18 directly — if your HTTP client already implements it, which most do, there is nothing else to install.

## Where to go next

- [Installation](getting-started/installation.md) and your [first cassette](getting-started/quick-start.md)
- [How It Works](concepts/how-it-works.md) for the architecture
- [Record Modes](concepts/record-modes.md) for what happens during recording vs. playback
- [VcrClient Reference](reference/vcr-client.md) for every option in one table
