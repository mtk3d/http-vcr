# Record Modes

Every `VcrClient` is opened in one of three `RecordMode` cases, controlling what happens when an incoming request doesn't match anything already in the cassette. There's also a fourth behavior, forced recording, that deliberately isn't one of these three — see the last section on this page.

## `RecordIfAbsent` (default)

- Cassette doesn't exist yet → record everything for real, write a new cassette.
- Cassette already exists → **replay only**. An unmatched request throws `NoMatchingInteractionException` instead of silently hitting the real API.

This is the mode for a new test: run it once against the real API to create the cassette, then every run after is pure replay.

```php
new VcrClient($inner, cassette: 'shopify/get-product', mode: RecordMode::RecordIfAbsent);
```

## `ExtendCassette`

Replays existing interactions, and **appends** any unmatched request as a new recording, without touching what's already there. Useful when a test's code path grows over time — a new call added to the method under test — and re-recording everything that already works isn't necessary.

## `PlaybackOnly`

Never records, ever, even if the cassette doesn't exist or nothing matches — any miss throws: a missing cassette or a changed request shape fails the test loudly instead of silently making a real network call.

This is a `RecordMode` you declare explicitly, like the other two. **http-vcr never swaps the declared mode based on the environment** — what a test declares is what it runs with, everywhere. Protecting CI is the job of a separate switch, described next.

## When recording is allowed at all

`VCR_ALLOW_RECORDING` sits *above* `RecordMode` rather than being one of its cases: set to `0`, it blocks the recording branch of *whatever* mode is declared — including `RecordIfAbsent`'s "cassette doesn't exist yet → record it" — without changing the declared mode. The result is the same visible behavior as `PlaybackOnly` (an unmatched request throws), reached without editing a single test.

It has three states, not two:

| `VCR_ALLOW_RECORDING` | Result |
|---|---|
| set to `1` or `0` | exactly that — an explicit value always wins |
| unset, CI detected | recording blocked |
| unset, no CI signal | recording allowed |

CI detection is deliberately narrow and fully enumerated, so it can be predicted without reading the source: any non-empty (and not `0`/`false`) value of `CI`, `CONTINUOUS_INTEGRATION`, `BUILD_NUMBER`, `JENKINS_URL`, or `TEAMCITY_VERSION`. The first two cover GitHub Actions, GitLab CI, CircleCI, Travis, Buildkite, Drone and most hosted runners; the rest cover Jenkins and TeamCity, which don't set them.

Both ways this can be wrong are survivable, which is the only reason a heuristic is acceptable here at all:

- **False positive** (a local machine that sets `CI` for unrelated reasons) → recording blocked, which is the safe direction. The error names the variable that triggered detection, so it's traceable rather than spooky action at a distance.
- **False negative** (an exotic runner not on the list) → recording allowed, which is the same as the local default. Setting `VCR_ALLOW_RECORDING=0` in the pipeline config is one line and is recommended regardless of detection.

The full precedence rules, including how this interacts with `VCR_ERASE_TAPE`, are in the [Environment Variables](../reference/environment.md) reference.

## Forced recording — not a `RecordMode` case

```bash
VCR_ERASE_TAPE=shopify/get-product vendor/bin/phpunit
```

`VCR_ERASE_TAPE` takes a cassette name — not a bare `1`/`0`. Whichever cassette it names is **truncated on open** — down to nothing, or down to whatever the selector spares ([locked interactions](../safety/locked-interactions.md), and other providers' traffic if the selector names one) — and every request that doesn't match what survived is executed for real and recorded fresh, regardless of whatever `RecordMode` was declared in code. Everything else in the same test run is untouched, even if the whole suite happens to run in the same process. Use it to deliberately re-record a cassette from scratch, for example after an upstream API changed its response shape.

A comma-separated list targets a few specific cassettes, and `VCR_ERASE_TAPE=all` erases every cassette the run opens. The other half of the [selector syntax](../reference/environment.md#vcr_erase_tape-selectors) narrows in the perpendicular direction — by external API rather than by file:

```bash
# every Shopify interaction, in every cassette the run opens; everything else replays
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify vendor/bin/phpunit

# only the Shopify interactions inside one cassette
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=sync/order-flow@shopify vendor/bin/phpunit
```

That's what makes a cassette recorded from a test talking to *two* APIs refreshable one API at a time: interactions belonging to other [providers](../integrations/phpunit.md#providers) survive the truncation and keep replaying, so the run needs credentials only for the API being refreshed. Which interaction belongs to which provider follows from the request host.

None of these examples set `VCR_ALLOW_RECORDING=1`, because locally it's already the default — `VCR_ERASE_TAPE` needs recording to be permitted, and on a developer machine it is. Add it explicitly if something in your shell sets `CI`; on CI itself, `VCR_ALLOW_RECORDING=0` [wins over `VCR_ERASE_TAPE`](../reference/environment.md#resolving-conflicts) by design.

A bare `VCR_ERASE_TAPE=1` is rejected with an error rather than treated as "erase everything". A boolean would make the shortest thing to type also the one with the widest blast radius: every cassette the run happened to open, which is the whole suite unless you remembered a test filter. Naming the target instead means the filter is only ever a speed optimization — skip the tests that can't change anything — and never the thing standing between you and an accidental mass re-record. If you do want everything, `all` says so out loud.

There's no `RecordMode` case for this, and no way to add one: forced recording exists only as an environment variable, so **"always hit the real API" can't be hardcoded into a test and committed**. It's always a deliberate, one-off decision about a specific run.

Forced recording respects [locked interactions](../safety/locked-interactions.md): anything marked `locked` in the cassette is excluded from the truncation and keeps being replayed from the existing recording — the mutating request that can't safely be repeated stays frozen while everything else refreshes. Everything that survives truncation — locked interactions, plus other providers' traffic when the selector named one — keeps its relative order and stays at the front of the file; freshly recorded interactions are appended after them, which is worth knowing if the cassette is also under [`StrictMode::InOrder`](../advanced/strict-mode.md).

A cassette locked in its entirety (`#[UseCassette(locked: true)]`) plus `VCR_ERASE_TAPE` erases nothing and records nothing — the file comes out of the run byte-for-byte identical. That's the lock working as promised, not an error, but the run reports it (`cassette fully locked, VCR_ERASE_TAPE had no effect`) so it doesn't look like a silently ignored variable.

This is different from `ExtendCassette`, which never removes or replaces what's already recorded — it only adds. Forced recording starts over.

## What happens to an interaction once it's replayed

By default, each interaction can be replayed exactly once per cassette session — once it's matched a request, it's "consumed" and won't be offered again for a later request, even an identical one. Set `repeatablePlayback: true` (per cassette or per interaction) when the code under test is expected to make the same call more than once, for example retry logic.
