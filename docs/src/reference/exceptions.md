# Exceptions

Everything http-vcr throws implements `HttpVcr\Exception\VcrException`, so a test that only wants to know "the VCR layer refused" can catch that one type.

```
VcrException
├── NoMatchingInteractionException
│   └── CassetteNotFoundException
├── StrictModeViolationException
├── StaleCassetteException
├── MissingEnvironmentVariableException
├── MissingDependencyException
├── RecordingNotAllowedException
├── CassetteFormatException
│   └── CassetteIntegrityException
├── VcrNetworkException        (also PSR-18 NetworkExceptionInterface)
└── VcrRequestException        (also PSR-18 RequestExceptionInterface)
```

| Exception | Thrown when |
|---|---|
| `NoMatchingInteractionException` | The cassette exists, but no still-unconsumed interaction matches the incoming request. See [Matching Requests](../concepts/matching.md#when-nothing-matches) for the message format. |
| `CassetteNotFoundException` | There's no cassette file at all — either never recorded, or the [scope](../advanced/scoping.md) changed and no file exists for the new one. In the scope case the message also lists the scopes that *do* exist. |
| `StrictModeViolationException` | [`AllPlayed` or `InOrder`](../advanced/strict-mode.md) wasn't satisfied when the cassette closed. |
| `StaleCassetteException` | A cassette crossed its [`staleAfter`](../advanced/stale-after.md) threshold and `VCR_ENFORCE_STALE_CHECK` asked for that to be an error. Lists the interactions that outlived it, with the date each went stale. |
| `MissingEnvironmentVariableException` | A variable listed in [`requiresEnv`](../integrations/phpunit.md#pre-validating-environment-variables) is empty at the moment a real request was about to be made. |
| `MissingDependencyException` | No PSR-17 factory implementation could be found to rebuild responses with, or `#[UseCassette]` found no HTTP client to record through. Names the specific interface or class it looked for. Rare in practice — whichever HTTP client you use already brings a factory along. |
| `RecordingNotAllowedException` | Something needed recording, but [`VCR_ALLOW_RECORDING=0`](environment.md) blocked it. The message says whether that `0` was set explicitly or inferred from CI detection, and from which variable. |
| `CassetteFormatException` | A cassette's `schemaVersion` is unknown or newer than this installation understands, or its contents can't be deserialized. |
| `CassetteIntegrityException` | A [sidecar file](cassette-format.md#sidecar-files) doesn't hash to the `bodySha256` recorded for it — hand-edited, truncated, or partially restored from a backup. |
| `VcrNetworkException` / `VcrRequestException` | Replaying a recorded [transport failure](../advanced/transport-errors.md). |

## Which one you get when nothing came back

Three of these all end with "the test didn't get a response," which makes them easy to confuse. The rule is that the exception names *why no recording happened*, not merely that there was no response:

- **No cassette file** (or none for the computed scope), with the mode not allowing a recording — `PlaybackOnly` → `CassetteNotFoundException`
- **The file is there**, but nothing in it matches, or the matching interactions were already consumed → `NoMatchingInteractionException`, in any mode. `RecordIfAbsent` against an existing cassette lands here, not on `CassetteNotFound`
- **A recording would have happened, but `VCR_ALLOW_RECORDING=0`** → `RecordingNotAllowedException`, whether what was missing was the file or just a match. This one takes precedence in the message over the two above, because it's the actual cause: the identical run with recording allowed would have succeeded

## Catching by PSR-18 contract

`VcrNetworkException` and `VcrRequestException` implement **two** things at once: their PSR-18 counterpart (`NetworkExceptionInterface` / `RequestExceptionInterface`, both extending `ClientExceptionInterface`) *and* `VcrException`.

That means application error handling written against the PSR-18 contract — as PSR-18-aware code should be — behaves under replay exactly as it does against a genuine network failure. And a test that catches `VcrException` broadly still catches these two, without needing a special case.

What http-vcr deliberately does **not** do is reconstruct the original client's exception class (`GuzzleHttp\Exception\ConnectException` and the like). PSR-18 standardizes the interfaces, not the constructors, so there's no safe general way to rebuild an arbitrary library's exception from stored data. The original class name is kept in the cassette as diagnostic metadata — see [Transport Errors](../advanced/transport-errors.md).

## `LogicException`, not `VcrException`

Misuse of the API throws a plain `LogicException` rather than a `VcrException`, because it's a bug in the calling code and not something a test should ever catch:

- registering a hook, matcher, or redaction rule after the cassette session's first request ([why](vcr-client.md#configuration-is-frozen-after-the-first-request))
- calling `VcrClient::configure()` after the first `VcrClient` exists in the process
- using a `VcrClient` built with `inner: null` on a path that needs a real request, without `withInner()`
- returning `null` from a [`beforePlayback`](../concepts/hooks.md#beforeplayback) hook
