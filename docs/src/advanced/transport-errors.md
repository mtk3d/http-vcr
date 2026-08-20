# Transport Errors

A 4xx or 5xx HTTP response is just a normal, valid interaction with a status code — nothing special. A **transport failure** — a timeout, DNS failure, connection refused — is different: the request never got a response at all, and PSR-18 represents that as an exception instead of a `ResponseInterface`.

## Recording transport failures is opt-in

By default, when a real request during recording throws a PSR-18 client exception (`NetworkExceptionInterface` / `RequestExceptionInterface`), http-vcr **doesn't persist it**. The exception passes through `VcrClient::sendRequest()` unchanged and nothing is written to the cassette — a transient network blip shouldn't become a permanent part of a regression test.

To deliberately record one — for testing an application's retry/error-handling code against a deterministic network failure, without actually severing a connection on every CI run:

```php
new VcrClient($inner, cassette: 'shopify/get-product', recordTransportErrors: true);
```

The interaction is then stored as a special variant with `"outcome": "error"` instead of a `response` — a category (`network` or `request`), a message, and the original exception's class name, kept purely as diagnostic metadata (see below, not for reconstruction).

That stored message goes through [redaction](../safety/redaction.md) like everything else in the interaction — HTTP client exceptions habitually quote the full request URL, and a URL is exactly where `?api_key=…` tends to live.

## Replay throws http-vcr's own exception, not the original class

PSR-18 only guarantees the *interfaces* (`NetworkExceptionInterface`, `RequestExceptionInterface`), not how any particular client's exception classes are constructed. There's no general, safe way to rebuild an arbitrary `GuzzleHttp\Exception\ConnectException` — or any other client library's exception — from what's stored on disk.

So replay throws `VcrNetworkException` / `VcrRequestException` — http-vcr's own classes, implementing the relevant PSR-18 interface. Application code that catches by the PSR-18 interface, as PSR-18-aware code should, behaves identically either way. Code that catches a specific Guzzle exception class directly was never something http-vcr could safely reproduce regardless — the original class name is recorded for diagnostics and tooling, not for reconstruction at replay time.
