# Environment Variables

Four variables control http-vcr from outside the code. Above all of them sits something that isn't a variable at all — a locked interaction — so the precedence list starts there.

| Level | Effect | Default |
|---|---|---|
| `locked: true` (data field or `#[UseCassette(locked: true)]`) | Overrides everything below: a locked interaction never makes a real request, whatever any variable says. Unlocking is manual only. See [Locked Interactions](../safety/locked-interactions.md) | `false` |
| `VCR_ALLOW_RECORDING` | `0` blocks the recording branch of any [record mode](../concepts/record-modes.md) — a missing cassette or scope fails instead of recording — **even if** `VCR_ERASE_TAPE` asks for a re-record | unset → `0` when CI is detected, `1` otherwise (see below) |
| `VCR_ERASE_TAPE` | A comma-separated list of `[cassette][@provider]` selectors — forces a fresh recording of whatever they select, regardless of the `mode` declared in code. A bare `1`/`0` is **not** a valid value. [Full syntax below](#vcr_erase_tape-selectors) | unset — nothing is erased |
| `VCR_IGNORE_STALE_CASSETTES` | `1` treats every cassette as fresh, whatever `recordedAt` says — overrides `VCR_ENFORCE_STALE_CHECK` | `0` |
| `VCR_ENFORCE_STALE_CHECK` | `1` makes a [stale](../advanced/stale-after.md) cassette fail the test instead of only being reported | `0` |

The four variables fall on two independent axes, and only the pairs within an axis can actually contradict each other:

- **Recording**: `VCR_ALLOW_RECORDING` and `VCR_ERASE_TAPE`.
- **Staleness**: `VCR_ENFORCE_STALE_CHECK` and `VCR_IGNORE_STALE_CASSETTES`.

## Resolving conflicts

`VCR_ALLOW_RECORDING=0` beats `VCR_ERASE_TAPE=shopify/get-product`: recording stays blocked, and http-vcr says so out loud (`recording disabled by VCR_ALLOW_RECORDING=0, ignoring VCR_ERASE_TAPE`) rather than letting one variable silently win over the other. The CI safety net outranks a manual override on purpose — a stray `VCR_ERASE_TAPE` left in a pipeline config shouldn't be able to open the door to the real API. The failure is a [`RecordingNotAllowedException`](exceptions.md), which names whether the `0` was set explicitly or inferred, and from which variable.

A locked interaction plus `VCR_ERASE_TAPE` plus `VCR_ALLOW_RECORDING=1` — that is, recording fully and deliberately enabled for that cassette — leaves the locked interaction untouched, with no error. That's not a conflict to report; it's the lock doing its one job.

## `VCR_ERASE_TAPE` selectors

A selector has two independently optional halves, separated by `@`: **which cassettes**, and **which interactions inside them**. Several selectors are separated by commas.

| Selector | Cassettes | Interactions in them |
|---|---|---|
| `shopify/get-product` | that one | all |
| `shopify/get-product,shopify/list-products` | those two | all |
| `all` | every cassette the run opens | all |
| `@shopify` | every cassette the run opens | only those belonging to [provider](../integrations/phpunit.md#providers) `shopify` |
| `@shop.myshopify.com` | every cassette the run opens | only those sent to that host — every host is implicitly its own provider, so this needs no configuration |
| `sync/order-flow@shopify` | that one | only `shopify`'s |
| `all@shopify` | the explicit spelling of `@shopify` | — |

A cassette records one test's traffic, which is why the cassette half of a selector names a scenario as readily as a service ([What one cassette covers](../concepts/how-it-works.md#what-one-cassette-covers)). The `@provider` half is what makes a test that talks to two APIs refreshable one API at a time: the interactions that don't belong to the named provider survive the truncation and replay from the cassette as usual, so the run only needs credentials for the API being refreshed. A name is resolved first against configured providers, then against the host of each interaction as the cassette is opened; a name matching neither erases nothing. [`http-vcr providers`](cli.md#providers) is what lists both sets, since checking a name against every cassette in the project means reading every cassette in the project.

`@` can't be confused with part of a cassette name, since names are sanitized to `[A-Za-z0-9_.-]` and `/`.

## `VCR_ERASE_TAPE` and scoped cassettes

The cassette half matches on the **base** name, not on the file name with its [scope](../advanced/scoping.md) suffix. `VCR_ERASE_TAPE=shopify/get-product` therefore catches the session whether the file actually opened is `shopify/get-product.2024-01.json` or `shopify/get-product.2024-04.json` — you name the cassette the test declares, not the file that happens to be on disk for the current API version.

## CI detection

`VCR_ALLOW_RECORDING` has three states, not two:

| Value | Result |
|---|---|
| `1` or `0` | exactly that — an explicit value always wins |
| unset, CI detected | recording blocked |
| unset, no CI signal | recording allowed |

Detection is a closed, enumerated list, so it can be predicted without reading the source. CI is considered detected when any of these is set to a non-empty value other than `0`/`false`:

- `CI`
- `CONTINUOUS_INTEGRATION`
- `BUILD_NUMBER`
- `JENKINS_URL`
- `TEAMCITY_VERSION`

The first two cover GitHub Actions, GitLab CI, CircleCI, Travis, Buildkite, Drone and most hosted runners, all of which set `CI=true` on their own. The last three cover Jenkins and TeamCity, which don't.

Detection is a default, not a rule. It exists so the common case needs no setup — and either way it can be wrong without much cost:

- **False positive** (a local machine that sets `CI` for its own reasons) → recording is blocked, the safe direction. The error names the variable that triggered detection.
- **False negative** (a runner that sets none of them) → recording is allowed, same as locally. Setting `VCR_ALLOW_RECORDING=0` in the pipeline is one line, and is worth doing regardless of detection.

In a Laravel app, the [Laravel bridge package](../integrations/laravel.md) adds a second condition to the same default: recording is allowed only when the environment is `local`/`testing` **and** no CI signal was detected. It narrows the default, never widens it — an environment check replacing CI detection would allow recording on CI, where tests run with `APP_ENV=testing`. An explicit variable still wins.

## Recipes

Recording is allowed by default on a developer machine, so the local recipes below don't set `VCR_ALLOW_RECORDING=1` — it's only worth spelling out when something in the shell sets `CI`, or to make the intent explicit in a script someone else will read.

```bash
# re-record exactly one cassette from scratch — safe without a test filter
VCR_ERASE_TAPE=shopify/get-product vendor/bin/phpunit

# refresh one API everywhere it appears, locked interactions excepted —
# including inside cassettes that also talk to other APIs
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify vendor/bin/phpunit

# same, but skip the tests that can't be affected (speed only, not safety)
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify \
  vendor/bin/phpunit --filter "$(vendor/bin/http-vcr tests --provider=shopify --filter-only)"

# CI: never touch the network, whatever any test declares
VCR_ALLOW_RECORDING=0 vendor/bin/phpunit

# let a hotfix through a pipeline that enforces staleness
VCR_IGNORE_STALE_CASSETTES=1 vendor/bin/phpunit
```
