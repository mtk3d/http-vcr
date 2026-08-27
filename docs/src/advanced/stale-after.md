# Auto Re-record (staleAfter)

APIs change. A cassette recorded six months ago might no longer reflect what the real endpoint returns today — and there's no way to know that from a passing test, since the test is, by design, no longer talking to the real API. `staleAfter` flags that without turning it into a build gate no one asked for.

```php
new VcrClient($inner, cassette: 'shopify/get-product', staleAfter: Stale::Week);
```

## Naming the interval

`Stale` covers the intervals a recording is realistically given, and reads as what it means at the point of declaration:

| Case | Interval |
|---|---|
| `Stale::Day` | `P1D` |
| `Stale::Week` | `P7D` |
| `Stale::Month` | `P1M` |
| `Stale::Quarter` | `P3M` |
| `Stale::Year` | `P1Y` |

Anywhere `staleAfter` is taken — the `VcrClient` constructor, `#[UseCassette]`, `Config::create()` — a `DateInterval` of your own works just as well, and is the way to say anything this list doesn't cover:

```php
#[UseCassette('shopify/get-product', staleAfter: Stale::Month)]
#[UseCassette('shopify/inventory', staleAfter: new DateInterval('PT6H'))]
```

Two of the five are worth having names for on their own: `P1M` and `PT1M` are a month and a minute, and the one that silences the check is the easy one to type by accident.

## What "stale" means

Staleness is tracked **per interaction**, not per file — an interaction is stale when `now() - interaction.recordedAt > staleAfter`. A cassette as a whole counts as stale if it has *at least one* stale interaction, but `stale` reports the specific interactions, not just the file. This matters in [`ExtendCassette`](../concepts/record-modes.md) mode, where a cassette grows over time: one interaction recorded months ago shouldn't make an otherwise-fresh file look entirely stale.

## It doesn't fail the build by default

Checking against `now()` is inherently non-deterministic between runs — the same commit can pass in a merge-request pipeline and fail an hour later on `main`, purely because `staleAfter` was crossed in between. So by default, staleness is **informational only**:

- `vendor/bin/http-vcr stale` lists what's stale, meant to run as a separate, non-blocking CI step ("cassettes to refresh"), not something that fails the build
- the test keeps using the "stale" cassette exactly as before

## Opting into enforcement

For teams that want it hard-enforced anyway:

```bash
VCR_ENFORCE_STALE_CHECK=1 vendor/bin/phpunit
```

This makes a stale cassette actually fail the test, with a [`StaleCassetteException`](../reference/exceptions.md) naming the interactions that outlived the threshold — a deliberate trade of some non-determinism for a forced re-record cadence. The check happens when the cassette is opened, so a run that is going to stop stops before the code under test is halfway through on replayed data. If this is turned on, set it identically in both merge-request and main-branch pipelines to avoid the two drifting apart.

For a one-off run that must pass regardless (a hotfix, say), override it:

```bash
VCR_IGNORE_STALE_CASSETTES=1 vendor/bin/phpunit
```

This treats every cassette as fresh, no matter what `recordedAt` says — see the [precedence table](../reference/environment.md) for how it interacts with everything else, including [locked interactions](../safety/locked-interactions.md), which sit above it.

## Testing your own `staleAfter`

"Now" comes from an injectable PSR-20 clock (`Psr\Clock\ClockInterface`), defaulting to `SystemClock`. Any PSR-20 implementation works — Symfony's `MockClock`, `lcobucci/clock`, your own — and `FrozenClock` ships with the package so that testing this needs no extra dependency:

```php
use HttpVcr\Clock\FrozenClock;

new VcrClient(
    $inner,
    cassette: 'shopify/get-product',
    staleAfter: new DateInterval('P7D'),
    clock: new FrozenClock(new DateTimeImmutable('2026-08-20T12:00:00Z')),
);
```

That lets a test assert what happens on either side of the threshold without waiting out real time or mocking global functions.

## Two independent axes

`staleAfter` is about elapsed time. [Scoping](scoping.md) is about a contract change visible in the URL. They're independent and can both be active: scope decides *which* file matters, `staleAfter` makes sure that file doesn't go stale even if the version never changes.

## Setting it per test, not just globally

The constructor form above applies for as long as that `VcrClient` instance lives. With the [PHPUnit attribute](../integrations/phpunit.md), the same threshold is set per test:

```php
#[UseCassette('shopify/get-product', staleAfter: new DateInterval('P7D'))]
public function testGetProduct(): void { /* ... */ }
```

This is useful when different integrations change at different rates — a fast-moving pricing endpoint might want a week, a stable product catalog might not need checking at all.

There's one wrinkle `staleAfter` has that `strictMode` doesn't: `strictMode` only matters while a test is actually running, so it doesn't need to be visible to anything else. `staleAfter`, on the other hand, is also read by `vendor/bin/http-vcr stale` — a CLI command that reports stale interactions **without running any tests**. Since the threshold can live only in an attribute, the CLI reads it the same way `tests` reads everything else about `#[UseCassette]`: by parsing the test files' AST, not by executing them. A cassette that no test declares `staleAfter` for is simply never checked — that's the correct, opt-in default, not a gap. If two different tests declare different `staleAfter` values for the same cassette name, the CLI reports the conflict rather than silently picking one.
