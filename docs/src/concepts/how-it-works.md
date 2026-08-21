# How It Works

http-vcr sits between application code and a real PSR-18 HTTP client, as a plain decorator:

```
Your code
    │  Psr\Http\Client\ClientInterface
    ▼
HttpVcr\VcrClient
    │
    ├── decides: replay from cassette, or make a real request?
    ├── Matching\RequestMatcherInterface[]     — which recorded interaction (if any) matches?
    ├── Hook\HookRegistry                      — beforeRecord / beforePlayback callables, transforming an
    │                                            interaction before it's written / before it's replayed
    │                                            (redaction is built on this — see Hooks)
    ├── PSR-17 factories                       — rebuild a live response object from the stored snapshot
    └── Persistence\CassettePersisterInterface — where cassettes live (filesystem by default)
            └── Serializer\CassetteSerializerInterface — the on-disk format (JSON by default)
    │
    ▼ (only when actually recording)
Real Psr\Http\Client\ClientInterface (Guzzle, Symfony, ...)
```

Because `VcrClient` itself implements `Psr\Http\Client\ClientInterface`, application code never knows it's talking to a decorator instead of the real client — `sendRequest()` has the exact same signature either way.

## What actually gets stored

A **cassette** is a JSON file containing a list of **interactions** — each one a recorded request and its response, plus a little metadata (when it was recorded, whether it's locked, and so on). See [The Cassette Format](cassette-format.md) for the full shape.

Interactions hold **plain values**, never live PSR-7 objects: method and URI as strings, headers as arrays, body as a string. That's not an implementation detail — PSR-7 bodies are `StreamInterface`, which is mutable, so one matcher reading a body could leave the next one with an empty stream. An incoming request is converted to that same value shape (`RecordedRequest`) once, at the edge of `sendRequest()`, and everything downstream — matchers, hooks, redaction — works on it. A live `ResponseInterface` is built back up, through PSR-17 factories, only at the very last moment before it's returned.

## What one cassette covers

A cassette is the recording of **one test's** HTTP traffic — not one API's. Nothing in the format ties a file to a single service: an interaction holds a request and what came back, and which external API it belongs to is worked out from the request's host whenever something needs to know, never stored. A test that pulls an order out of Shopify and opens a Zendesk ticket from it records both halves into one file and replays both from it.

That's what decides how to name one. A cassette name is a path inside the cassette directory (`shopify/get-product` → `tests/Cassettes/shopify/get-product.json`) and http-vcr reads nothing into it — the leading segment is free to be a service, a module, or absent. So name it after **what the test does**: `shopify/get-product` when the test really is one call to one API, `sync/order-flow` when it walks through several.

Two things follow, and both bite when one file is shared between tests:

- `StrictMode::AllPlayed` and `InOrder` are assertions about a single file. A cassette two tests write into fails them for reasons belonging to the other test.
- `ExtendCassette` appends whatever didn't match, so a shared file grows into the union of every test that ever touched it.

One file per test avoids both. When such a file spans several APIs, `VCR_ERASE_TAPE=@shopify` re-records just that API's interactions inside it and leaves the rest replaying — see [`VCR_ERASE_TAPE` selectors](../reference/environment.md#vcr_erase_tape-selectors).

## What decides "replay or record"

Two things, together:

1. The **record mode** — `RecordIfAbsent`, `ExtendCassette`, or `PlaybackOnly` — plus two separate, env-only switches: whether recording is permitted at all, and forced re-recording. See [Record Modes](record-modes.md) and the [Environment Variables](../reference/environment.md) reference.
2. Whether an incoming request **matches** an interaction already in the cassette, decided by a composable set of matchers (method, URI, headers, body, ...). See [Matching Requests](matching.md).

If a request matches an existing, unconsumed interaction, it's replayed — no network call, ever. If it doesn't match anything, what happens next depends entirely on the record mode; see [Exceptions](../reference/exceptions.md#which-one-you-get-when-nothing-came-back) for which failure you get when it isn't allowed to record.

On either path, an interaction passes through the [hook chain](hooks.md) — `beforeRecord` on the way to disk, `beforePlayback` on the way out — which is also where redaction happens.

## No global state

Nothing here patches `curl_exec`, swaps a stream wrapper, or touches anything outside the `VcrClient` instance that was constructed. Two tests running in the same process with two different `VcrClient` instances — different cassettes, different inner clients — never interfere with each other. There's no shared, process-wide state to reset between tests.

Project-wide configuration is the one thing that *is* process-wide, and it's frozen before the first `VcrClient` exists precisely so this claim stays true rather than depending on test execution order — see [Configuration Reference](../reference/configuration.md#vcrclientconfigure).
