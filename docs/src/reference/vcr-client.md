# VcrClient Reference

Everything a `VcrClient` can be configured with, in one place. Each parameter is introduced in context elsewhere; this page is the assembled list.

```php
public function __construct(
    ?ClientInterface $inner,
    string $cassette,
    RecordMode $mode = RecordMode::RecordIfAbsent,
    array $matchers = [],
    ?StrictMode $strictMode = null,
    DateInterval|Stale|null $staleAfter = null,
    array $requiresEnv = [],
    bool $recordTransportErrors = false,
    bool $decodeCompressedResponse = true,
    ?int $inlineBodyLimit = null,
    bool $repeatablePlayback = false,
    bool $locked = false,
    ?CassetteScopeResolverInterface $scopeResolver = null,
    ?CassettePersisterInterface $persister = null,
    ?CassetteSerializerInterface $serializer = null,
    ?ResponseFactoryInterface $responseFactory = null,
    ?StreamFactoryInterface $streamFactory = null,
    ?ClockInterface $clock = null,
    ?callable $warn = null,
) {}

public function withInner(ClientInterface $inner): self;
```

| Parameter | Default | What it does |
|---|---|---|
| `inner` | — | The real PSR-18 client to record through. `null` is only valid when [`withInner()`](../integrations/guzzle.md#where-the-real-request-goes) will supply one; using an inner-less instance on the recording path throws `LogicException`. |
| `cassette` | — | Cassette name, without extension or [scope](../advanced/scoping.md) suffix. A path relative to `cassetteDirectory`: `shopify/get-product` → `tests/Cassettes/shopify/get-product.yaml`. |
| `mode` | `RecordIfAbsent` | [Record mode](../concepts/record-modes.md). |
| `matchers` | `[]` → `[Method, Uri, QueryString]` | [Matchers](../concepts/matching.md), combined with AND. Empty means the project default from config. |
| `strictMode` | `None` | [`AllPlayed` / `InOrder`](../advanced/strict-mode.md) assertions at cassette close. |
| `staleAfter` | `null` | [Staleness threshold](../advanced/stale-after.md), as a [named interval](../advanced/stale-after.md#naming-the-interval) (`Stale::Week`) or a `DateInterval` of your own. `null` means freshness isn't tracked. |
| `requiresEnv` | `[]` | Environment variables that must be set — checked on the recording branch, not at construction. See [PHPUnit](../integrations/phpunit.md#pre-validating-environment-variables). |
| `recordTransportErrors` | `false` | Whether to persist [transport failures](../advanced/transport-errors.md) instead of letting them pass through unrecorded. |
| `decodeCompressedResponse` | `true` | Decompress `Content-Encoding: gzip`/`br`/`deflate` before storing, and strip the header. Turn off only when compression itself is what's under test. |
| `inlineBodyLimit` | `1048576` (1 MiB) | Bodies above this go to a [sidecar file](cassette-format.md#sidecar-files) instead of into the cassette. |
| `repeatablePlayback` | `false` | Cassette-wide default for whether interactions are consumed on replay; overridable per interaction in the data. |
| `locked` | `false` | [Locks the whole cassette](../safety/locked-interactions.md) from code, on top of the per-interaction data field. |
| `scopeResolver` | `NullScopeResolver` | [Scoping](../advanced/scoping.md) — splits one cassette name across several files by API version. |
| `persister` / `serializer` | from config | [Where and in what format](../advanced/storage-and-formats.md) cassettes are stored. |
| `responseFactory` / `streamFactory` | detected | PSR-17, used to rebuild a replayed response. See below. |
| `warn` | standard error | Where this session's warnings go: what the [secret scan](../safety/redaction.md#the-automatic-check-after-recording) found, and a forced recording a [lock](../safety/locked-interactions.md) made a no-op. The PHPUnit bridge passes its own, so a run prints them together at the end instead of scattered through the output. |
| `clock` | `SystemClock` | Any PSR-20 `Psr\Clock\ClockInterface` — the source of "now" for `staleAfter`; `FrozenClock` ships with the package for testing that. |

Where a parameter is nullable, `null` means "whatever the project configured" — the Default column is the value that applies when nothing configured one either.

Every `#[UseCassette(...)]` argument is one of these parameters under the same name, the attribute adding nothing of its own — `requiresEnv` really is a core parameter, since only the client knows the moment a real request is about to happen. [Providers](../integrations/phpunit.md#providers) are project-wide configuration rather than a constructor argument: they describe which APIs the project talks to, so `VCR_ERASE_TAPE=@shopify` has to mean the same thing for every instance in a run.

## PSR-17 factories

Rebuilding a response from a cassette means constructing a `ResponseInterface` and a `StreamInterface`, and `psr/http-factory` ships interfaces only — so the core cannot work without an implementation. Those two, and only those two, are `VcrClient` constructor parameters: the core never builds a request (it receives them) and never builds a URI (it compares them as strings).

`RequestFactoryInterface` and `UriFactoryInterface` are needed by exactly one thing — the [Symfony bridge](../integrations/symfony.md), which builds PSR-7 requests out of Symfony's `request()` arguments — so they're parameters of *that* constructor instead. Same resolution mechanism, applied lazily: a project that never touches that bridge never needs either one.

Resolution order, first hit wins:

1. an explicit constructor argument, then [`Config`/`VcrClient::configure()`](configuration.md);
2. an implementation detected via `class_exists` from a closed, enumerated list:
   - `Nyholm\Psr7\Factory\Psr17Factory` — one class implementing all four interfaces
   - `GuzzleHttp\Psr7\HttpFactory` — likewise
   - `Laminas\Diactoros\ResponseFactory` / `StreamFactory` / `RequestFactory` / `UriFactory` — four separate classes, so this provider is resolved interface by interface
3. failing that, a [`MissingDependencyException`](exceptions.md) naming **which** interface is missing. Never a silent failure halfway through the first request.

## Configuration is frozen after the first request

`VcrClient` is a service object configured imperatively after construction — `redact()` and the other redaction helpers, `includeSensitiveHeaders()`, [`beforeRecord()`/`beforePlayback()`](../concepts/hooks.md), and adding matchers outside the constructor. All of it must happen **before the cassette session's first `sendRequest()`**; afterwards each of those methods throws `LogicException`.

The point is to make "the hook was registered too late, so the first interaction went to disk unredacted" impossible rather than merely unlikely.

Note "session," not "instance." `withInner()` returns a new object, and the [Guzzle middleware](../integrations/guzzle.md) calls it on every single request — so a flag stored on the instance would reset constantly and never actually freeze anything under a middleware setup, which is precisely where it's needed. The flag lives with the rest of the session state (replay-consumption counters, the file lock) in the shared cassette manager, so it survives `withInner()`.

Matchers are unaffected by any of this, since they're values rather than configurable services: `ignoreJsonField()`, `matchJsonField()`, `ignoreQueryParam()` and `matchOnlyQueryParams()` return a new matcher rather than mutating one.

## Global configuration is frozen too, earlier

`VcrClient::configure()` sets project-wide defaults and is meant to be called **once, before the first `VcrClient` exists in the process** — typically a PHPUnit bootstrap. Calling it afterwards throws `LogicException`. Without that, the "no global state" promise would be false: two tests in the same process could see different defaults depending on execution order. See [Configuration Reference](configuration.md).
