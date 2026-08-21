# Guzzle

**Use the middleware.** It sits inside Guzzle's handler stack, so it sees every request no matter which of Guzzle's two APIs the calling code used:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use HttpVcr\Bridge\Guzzle\VcrMiddleware;

$stack = HandlerStack::create();
$stack->push(VcrMiddleware::create($vcr));

$guzzle = new Client(['handler' => $stack]);
```

The plain decorator — `new VcrClient(new GuzzleHttp\Client(), cassette: '…')` — also works, and needs no middleware at all, but only if **every** call in the codebase goes through `sendRequest()`. That's a narrower condition than it sounds, and the next section is about why.

## Why: Guzzle has two APIs

`GuzzleHttp\Client` implements PSR-18's `sendRequest()`, but it also has its own, older, richer API: `request()`, the magic verb methods (`get()`, `post()`, ...), `requestAsync()`, and `Pool` for concurrent requests. Both APIs route through the same internal handler stack, but only one of them — `sendRequest()` — is visible to the decorator.

```php
// invisible to VcrClient — it never touches sendRequest()
$response = $guzzle->get('https://shop.myshopify.com/admin/api/2024-01/products/123.json');
```

If any part of a codebase calls Guzzle's native API directly on the underlying client instead of going through the wrapped `VcrClient`, that call bypasses recording and replay entirely — a real request to a real API, with no cassette involved, and no warning that it happened.

## The middleware in full

`VcrMiddleware` sits below both APIs, so every entry point is covered:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use HttpVcr\Bridge\Guzzle\VcrMiddleware;
use HttpVcr\VcrClient;

// note: no inner client here — the middleware supplies one, see below
$vcr = new VcrClient(inner: null, cassette: 'shopify/get-product');

$stack = HandlerStack::create();
$stack->push(VcrMiddleware::create($vcr));

$guzzle = new Client(['handler' => $stack]);

$guzzle->get('...');            // recorded / replayed
$guzzle->sendRequest($request); // recorded / replayed
$guzzle->requestAsync('...');   // recorded / replayed
```

If a codebase already has other middleware on its `HandlerStack` (retry, logging), push `VcrMiddleware` onto that same stack — it doesn't replace or duplicate any of it, it's just one more layer.

### Where it sits among other middleware

Guzzle applies a handler stack from the bottom up, so push order decides which side of the cassette each middleware ends up on:

- pushed **before** `VcrMiddleware` (including everything `HandlerStack::create()` brings along — redirects, `http_errors`, cookies) — sits above it and treats a replayed response exactly like one off the wire;
- pushed **after** it — sits between the cassette and the transport, so it only ever sees requests that are actually being recorded.

Retry and logging usually belong above; anything that signs or instruments the real connection belongs below.

### Where the real request goes

The middleware doesn't let `VcrClient` use its own inner client for recording. It wraps the **next handler in the stack** as a PSR-18 client and hands that over per request:

```php
$vcr->withInner($nextHandlerAsPsr18Client)->sendRequest($request);
```

`withInner()` returns a new `VcrClient` with the same cassette session and configuration. Two things fall out of that, both of which you'd otherwise have to debug the hard way: a real request travels through the rest of the stack (retry, logging) instead of jumping around it, and passing the *same* Guzzle client as the inner client can't set off infinite recursion through the middleware. `VcrClient` is constructed exactly the same way for the decorator and for the middleware — one shape to learn, and `#[UseCassette]` maps onto both.

### Request options at replay time

Guzzle options that only make sense for a real transport — `timeout`, `proxy`, `cert`, `sink`, `on_stats` — are ignored when a response comes from a cassette; there's no connection for them to apply to. Most of those are invisible to application code anyway. The two that aren't: `sink` (writing the response body straight to a file) and `on_stats` (transfer metrics) will not do their thing on a replayed request.

**When the plain decorator is enough:** when the whole codebase disciplines itself to `Psr\Http\Client\ClientInterface` and `sendRequest()`, with no exceptions — which is worth checking rather than assuming, since a single `$client->get()` somewhere is enough to punch a hole in it. [Shopify's official SDK](../examples/shopify-official-sdk.md) is a worked example of an SDK that passes that test even though it builds its own Guzzle client internally.

## One more difference worth knowing

Guzzle's own documentation notes that `sendRequest()` — the PSR-18 path — does **not** follow redirects automatically, unlike `request()`/`requestAsync()`, in order to meet PSR-18's stricter requirements. So even code that goes through PSR-18 exclusively may see a plain `3xx` response where "normal" Guzzle usage would have followed it transparently. http-vcr records and replays exactly what's visible at the PSR-18 boundary — a single response, not a followed redirect chain.

## Real-world example

For a concrete case study of the decorator-vs-middleware question against an actual Guzzle-based SDK — including a case where the SDK builds its own client internally and the decorator still turns out to be enough — see [Shopify's Official PHP SDK](../examples/shopify-official-sdk.md).
