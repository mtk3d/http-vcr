# Hooks

Everything http-vcr does to an interaction on its way to disk, or on its way back out, goes through one mechanism: a list of callables that take an `Interaction` and return a new one. [Redaction](../safety/redaction.md) isn't a special case in the core — it's a pair of these hooks with a convenience API on top.

```php
$vcr->beforeRecord(fn (Interaction $i) => /* ... */);
$vcr->beforePlayback(fn (Interaction $i) => /* ... */);
```

`Interaction` is a `readonly` class, and so are the `RecordedRequest`/`RecordedResponse` it holds. A hook never mutates what it was handed — it returns a new instance (or the same one unchanged, when there's nothing to do). That's what makes the ordering guarantees below meaningful: no hook can quietly change what an earlier one already looked at.

## What a hook is handed

```php
final readonly class Interaction {
    public RecordedRequest $request;
    public ?RecordedResponse $response;   // null only when $outcome is Outcome::Error
    public Outcome $outcome;              // Outcome::Success | Outcome::Error
    public ?RecordedError $error;         // category / message / original class, null on success
    public DateTimeImmutable $recordedAt;
    public bool $locked;
    public bool $repeatablePlayback;

    public function withRequest(RecordedRequest $request): self;
    public function withResponse(RecordedResponse $response): self;
    public function withError(RecordedError $error): self;
}

final readonly class RecordedRequest {    // RecordedResponse is the same, with $status instead of $method/$uri
    public string $method;
    public string $uri;
    /** @var array<string, string[]> */
    public array $headers;
    public string $body;
    public ?string $bodyEncoding;         // 'base64', or null for text

    public function withUri(string $uri): self;
    public function withHeaders(array $headers): self;
    public function withHeader(string $name, string|array $value): self;
    public function withoutHeader(string $name): self;
    public function withBody(string $body, ?string $encoding = null): self;
}
```

Every `with*()` returns a new instance, so a hook is a chain of expressions rather than a sequence of assignments:

```php
// drop a huge response body the test doesn't care about, so it never reaches disk
$vcr->beforeRecord(fn (Interaction $i) => $i->withResponse($i->response->withBody('')));

// strip a volatile header
$vcr->beforeRecord(fn (Interaction $i) => $i->withResponse($i->response->withoutHeader('X-Request-Id')));
```

`bodyRef` and `bodySha256` are deliberately *not* part of this surface. Splitting a large body out to a [sidecar file](../reference/cassette-format.md#sidecar-files) happens during serialization, after every hook has run, so a hook always sees the full content in `$body` and never has to wonder whether it got a reference instead.

## `beforeRecord`

Runs on the way to disk, after a real request has completed and before anything is serialized.

```php
// don't persist transient upstream failures into a regression test
$vcr->beforeRecord(fn (Interaction $i) => $i->response?->status >= 500 ? null : $i);
```

Its type is `callable(Interaction): ?Interaction`. **`null` is a legal return value** and means "don't record this interaction." That isn't an error: the request was really made, its response goes back to the code under test as usual, and only the cassette write is skipped. The first hook to return `null` ends the chain — the ones after it have nothing left to receive.

Other things this is the right place for: stripping a volatile response header, replacing an enormous body the test doesn't care about with an empty one so it never reaches disk at all, normalizing a timestamp the API echoes back.

## `beforePlayback`

Runs on the way out of the cassette, and — this is the part worth internalizing — **before the matchers compare anything**. A recorded request transformed here is the one matching sees.

Its type is `callable(Interaction): Interaction`. `null` is *not* allowed: the interaction already exists and has already been matched, and "don't replay it" isn't an answer to "what should `sendRequest()` return?". Returning `null` here is a programming error and throws `LogicException` rather than being silently swallowed.

This is where two-way redaction puts the real value back — both into the recorded request, so it can be compared against a live one, and into the recorded response, so application code receives a usable token rather than a placeholder.

## Ordering

Within one direction, hooks run in registration order (FIFO). Rules declared in [`http-vcr.php`](../reference/configuration.md) are registered before anything added imperatively on an instance, so a project-wide redaction rule always runs first.

Across mechanisms, the write path has a fixed order that matters for correctness — decompression, then `beforeRecord` (redaction included), then the sidecar threshold check, then serialization. It's spelled out in the [Cassette Format Reference](../reference/cassette-format.md#write-pipeline-order): the reason it's fixed is that redaction has to see decompressed text, and a sidecar must never be written before redaction has run over it.

## Hooks and matching

One deliberate asymmetry: before matching, http-vcr applies the record-direction **redaction** transform to the incoming request, so one-way redacted fields line up on both sides (see [Matching Requests](matching.md#redacted-values-are-normalized-on-both-sides)). It does *not* run the rest of the `beforeRecord` chain there. Your hooks may have side effects, or assume they run once per recorded interaction; redaction is a pure substitution the library owns, and is the only thing that has to hold on both sides for matching to work at all.

## When to register them

Hooks are part of a `VcrClient`'s configuration, so they follow the same rule as everything else: register them before the first request of the cassette session, or get a `LogicException`. See [VcrClient Reference](../reference/vcr-client.md#configuration-is-frozen-after-the-first-request).
