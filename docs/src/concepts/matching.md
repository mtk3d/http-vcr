# Matching Requests

When application code makes a request, http-vcr has to decide which recorded interaction, if any, it corresponds to. That decision is made by a composable list of matchers — by default method, URI and query string, extendable with more.

```php
// the default set, plus one more — passing `matchers:` replaces the default
// list outright rather than adding to it
new VcrClient($inner, cassette: 'shopify/get-product', matchers: [
    new MethodMatcher(),
    new UriMatcher(),
    new QueryStringMatcher(),
    new HeadersMatcher(['X-Shop-Domain']),
]);
```

All configured matchers must agree (logical AND) for an interaction to count as a match.

## The default set

`[MethodMatcher, UriMatcher, QueryStringMatcher]`. The query string is part of the default deliberately: `UriMatcher` compares scheme, host and path only, so without it `?page=1` and `?page=2` would be the same interaction — and that failure is silent. The test wouldn't error; the code under test would just receive page two where it asked for page one, and find out several assertions later, if at all. A default should fail loudly rather than guess.

The cost lands on throwaway parameters that change every run — a cache-buster `?_=1712345678`, a `?nonce=…`. Those now produce a *missing match*, which is noisy but obvious and has an immediate fix: drop `QueryStringMatcher` from the `matchers:` list, or supply your own. That trade is on purpose — a false miss announces itself, a silent match against the wrong interaction doesn't.

Matchers compare two `RecordedRequest` snapshots — the recorded one and the incoming one — not live PSR-7 objects:

```php
interface RequestMatcherInterface {
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool;
}
```

Two reasons that matters if you write your own: a snapshot's body is a plain string, so one matcher can't drain the stream out from under the next one, and a body large enough to have been [written to a sidecar file](cassette-format.md#large-or-binary-bodies) isn't read off disk for matchers that never look at it.

## Built-in matchers

- **`MethodMatcher`** — HTTP method, exact match.
- **`UriMatcher`** — scheme + host + path, normalized (lowercase host, default ports stripped). The query string is handled separately.
- **`HostMatcher`** — just the host, for cases where matching the full path is too strict.
- **`QueryStringMatcher`** — query params as an unordered set (`?a=1&b=2` equals `?b=2&a=1`), but repeated keys keep their order (`?tag=a&tag=b` is treated as a list).
- **`HeadersMatcher`** — subset match by default: recorded headers must be present in the incoming request, but extra headers on the incoming side don't fail the match. This matters because different HTTP client libraries add their own headers (`User-Agent`, `Accept-Encoding`) that have nothing to do with application code. Header names are lowercased before comparison, since PSR-7 treats them as case-insensitive but a recorded cassette and a live client don't necessarily agree on capitalization. It's the only built-in matcher that takes constructor arguments:

  ```php
  public function __construct(array $headers = [], bool $exact = false) {}
  ```

  `$headers` lists the header names to check; empty means every header on the *recorded* request. `exact: true` opts into a strict 1:1 comparison of the whole header set, for a test that specifically cares about it.
- **`BodyMatcher`** — raw body, exact match.
- **`BodyJsonMatcher`** — semantic JSON match: `{"a":1,"b":2}` matches `{"b":2,"a":1}`. Scalar types are compared strictly and array order is significant. Falls back to a raw comparison when either body isn't valid JSON.

  Two builder methods handle values that legitimately change every run (UUIDs, generated timestamps) — redaction can't help there, since it replaces a value known in advance rather than matching one that isn't:

  ```php
  (new BodyJsonMatcher())
      ->ignoreJsonField('/transactionId')                       // any value on either side counts as equal
      ->matchJsonField('/requestId', '/^[0-9a-f-]{36}$/');      // must *look* like a UUID, need not be identical
  ```

  Both return a new matcher rather than mutating the receiver, so a matcher stays a value that can be built in one expression inside the `matchers:` array.

## Redacted values are normalized on both sides

A value redacted **two-way** (`redact()` with a value provider, or a helper given one) is restored to its real value on the recorded side before the comparison, and matched normally.

A value redacted **one-way** — the four [auto-redacted headers](../safety/redaction.md#what-happens-without-any-configuration), or any `redactHeader()`/`redactJsonField()`/`redactQueryParam()`/`redactFormField()` call without a value provider — is stored as a placeholder http-vcr has no way to turn back into the original. Comparing `<REDACTED-AUTHORIZATION>` against a real token would never match, so http-vcr goes the other direction: it applies the same record-direction redaction to the **incoming** request before matching, leaving the same placeholder on both sides. Otherwise turning on redaction would break replay, which is exactly the failure mode http-vcr exists to avoid.

The visible effect is that a one-way redacted field stops distinguishing interactions — two recordings that differ only in their `Authorization` header become indistinguishable to the matchers. The reason for doing it by normalizing rather than by teaching matchers to skip fields: it needs no extra matcher API, and it works for `BodyMatcher` too, where "skip the `client_secret` field" has no meaning on a raw string. Only the redaction transform is applied to the incoming request — not the rest of the [`beforeRecord` chain](hooks.md), which may have side effects and is meant to run once per recorded interaction.

If you specifically need to match on an auto-redacted header, opt it out of redaction with `includeSensitiveHeaders(['Authorization'])` — one deliberate decision rather than two independent settings that have to agree.

## When nothing matches

Whenever an unmatched request isn't allowed to fall through to a real recording — in `PlaybackOnly` mode, or in `RecordIfAbsent` once the cassette already exists — it throws `NoMatchingInteractionException`, and the message is built to actually help with debugging it:

```
No matching interaction for GET https://shop.myshopify.com/admin/api/2024-01/products/123.json

Cassette tests/Cassettes/shopify/get-product.json, 2 unconsumed interactions:
  #1  BodyJsonMatcher: field "status" expected "active", got "pending"
  #2  UriMatcher: expected path "/admin/api/2024-01/products/124.json"
```

It shows the first matcher that rejected each unconsumed interaction, with a short expected-vs-actual comparison for that matcher — not a wall of every matcher's opinion on every interaction, and not just "nothing matched" with no further clue. Interactions rejected on `MethodMatcher`/`UriMatcher` stop there rather than reporting matchers that never got to see the rest of the request.

When it's `VCR_ALLOW_RECORDING=0` that's standing in the way of a recording — the run *would* have recorded, and would have succeeded with recording allowed — the exception is `RecordingNotAllowedException` instead, naming that variable as the actual cause. And when there's no cassette file at all, it's `CassetteNotFoundException`. See [Exceptions](../reference/exceptions.md) for which one you get when.
