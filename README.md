# http-vcr

**Record real HTTP interactions once, replay them on every test run after that.**

[![CI](https://github.com/mtk3d/http-vcr/actions/workflows/ci.yml/badge.svg)](https://github.com/mtk3d/http-vcr/actions/workflows/ci.yml)
[![PHP 8.2+](https://img.shields.io/badge/php-8.2%20%E2%80%93%208.5-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)
[![Documentation](https://img.shields.io/badge/docs-mtk3d.github.io%2Fhttp--vcr-blue.svg)](https://mtk3d.github.io/http-vcr/)

The test suite for "the code that talks to Shopify/Stripe/Zendesk" becomes fast,
deterministic, and runnable in CI with no network access and no API credentials.

http-vcr is a decorator over [PSR-18](https://www.php-fig.org/psr/psr-18/)
(`Psr\Http\Client\ClientInterface`). Anything that already speaks PSR-18 (Guzzle 7+,
Symfony's `Psr18Client`, php-http, Buzz) works unchanged, and two `VcrClient` instances in
one process never interfere — nothing outside the instance you construct is touched.

```php
$vcr = new VcrClient($realClient, cassette: 'shopify/get-product');

$response = $vcr->sendRequest($request);
// first run:  the real request happens, and is recorded to
//             tests/Cassettes/shopify/get-product.json
// every run after: no network call — the recorded response is replayed
```

Recording works with no setup on a developer machine, and is refused on CI, so a missing
cassette fails loudly instead of quietly reaching for a real API without credentials.

---

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Without PHPUnit](#without-phpunit)
- [What lands on disk](#what-lands-on-disk)
- [Record modes](#record-modes)
- [Re-recording](#re-recording)
- [Matching](#matching)
- [Redaction and secret scanning](#redaction-and-secret-scanning)
- [More control](#more-control)
- [Framework integration](#framework-integration)
- [CLI](#cli)
- [Configuration](#configuration)
- [Documentation](#documentation)
- [Dependencies](#dependencies)
- [Development](#development)
- [License](#license)

## Installation

```bash
composer require --dev mtk3d/http-vcr
```

> No release is tagged yet. Until then, point Composer at the repository:
>
> ```bash
> composer config repositories.http-vcr vcs https://github.com/mtk3d/http-vcr
> composer require --dev mtk3d/http-vcr:dev-master
> ```

You need PHP 8.2+ and an HTTP client to wrap — whichever one the project already uses. To
rebuild a replayed response http-vcr also needs a PSR-17 factory, and it takes that from
the client library you already have (Guzzle ships one, Symfony's client pulls one in). If
nothing usable is found it stops before the first request rather than partway through one,
and `composer require --dev nyholm/psr7` settles it.

## Quick start

One line in `phpunit.xml` registers the extension behind `#[UseCassette]`. PHPUnit has no
auto-discovery for extensions, so this is the one thing http-vcr can't do for you:

```xml
<extensions>
    <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
</extensions>
```

That's the whole setup — no bootstrap code, no config file, no cassette directory to
create. Then put the attribute on the test and take the client from the trait:

```php
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;

final class ShopifyClientTest extends TestCase
{
    use InteractsWithCassettes;

    #[UseCassette('shopify/get-product')]
    public function testGetProduct(): void
    {
        $shopify = new ShopifyClient($this->vcrClient(), new GuzzleHttp\Psr7\HttpFactory());

        $product = $shopify->getProduct('123');

        $this->assertSame('T-Shirt', $product['title']);
    }
}
```

`$this->vcrClient()` is a `Psr\Http\Client\ClientInterface`, so it drops into the exact
spot the real client occupied — no interface changes, no test-only branch in the code under
test.

**First run** — with a real key available, the call goes over the wire and is written to
`tests/Cassettes/shopify/get-product.json`:

```bash
SHOPIFY_API_KEY=sk_live_xxx vendor/bin/phpunit --filter testGetProduct
```

**Every run after** — the cassette exists, so the same test replays it. No network, no API
key, no flakiness:

```bash
vendor/bin/phpunit
```

Commit the cassette next to the test. Credentials in `Authorization` and `Cookie` headers
were [replaced with placeholders](#redaction-and-secret-scanning) before it hit disk.

Every field of the attribute is a `VcrClient` constructor parameter under the same name:

```php
#[UseCassette(
    'shopify/checkout',
    mode: RecordMode::ExtendCassette,
    strictMode: StrictMode::InOrder,
    staleAfter: new DateInterval('P7D'),
    requiresEnv: ['SHOPIFY_API_KEY'],
    locked: true,
)]
```

On a class it applies to every test method in it; a method-level attribute replaces a
class-level one outright rather than merging with it.

## Without PHPUnit

The attribute is a convenience over an ordinary object. In a script, or under a different
test framework, construct it directly — everything the attribute sets is a constructor
argument or a method on this object:

```php
use HttpVcr\VcrClient;

$vcr = new VcrClient(
    inner: new GuzzleHttp\Client(),
    cassette: 'shopify/get-product',
);
```

## What lands on disk

A cassette is a plain JSON file, meant to be read in review and edited by hand when needed:

```json
{
    "schemaVersion": 1,
    "interactions": [
        {
            "request": {
                "method": "GET",
                "uri": "https://api.example.com/greeting",
                "headers": {},
                "body": ""
            },
            "response": {
                "status": 200,
                "headers": { "Content-Type": ["application/json"] },
                "body": "{\"hello\":\"world\"}"
            },
            "outcome": "success",
            "recordedAt": "2026-08-21T10:00:00+00:00"
        }
    ]
}
```

Cassettes live in `tests/Cassettes/` by default, with the cassette name as a path inside
it. Bodies that are binary or oversized go to sidecar files; compressed responses are
decoded before storage. YAML is available as an opt-in serializer, and HAR import/export
moves traffic to and from a browser's Network tab, Postman or a proxy.

## Record modes

Three cases decide what happens when an incoming request matches nothing in the cassette:

| `RecordMode` | Behavior |
|---|---|
| `RecordIfAbsent` *(default)* | No cassette → record everything. Cassette exists → replay only; an unmatched request throws. |
| `ExtendCassette` | Replays what exists and appends unmatched requests as new recordings, leaving the rest untouched. |
| `PlaybackOnly` | Never records. A missing cassette or a changed request shape fails loudly. |

The declared mode never changes based on the environment. Protecting CI is a separate
switch, `VCR_ALLOW_RECORDING`, which sits *above* `RecordMode` and blocks the recording
branch of whichever mode is declared:

| `VCR_ALLOW_RECORDING` | Result |
|---|---|
| `1` or `0` | exactly that — an explicit value always wins |
| unset, CI detected | recording blocked |
| unset, no CI signal | recording allowed |

CI detection is narrow and fully enumerated: a non-empty `CI`, `CONTINUOUS_INTEGRATION`,
`BUILD_NUMBER`, `JENKINS_URL` or `TEAMCITY_VERSION`. Setting `VCR_ALLOW_RECORDING=0` in the
pipeline is one line and recommended regardless.

## Re-recording

`VCR_ERASE_TAPE` names what to erase and record fresh. It takes a target, never a bare
`1` — so the shortest thing to type is not also the one with the widest blast radius:

```bash
# one cassette
VCR_ERASE_TAPE=shopify/get-product vendor/bin/phpunit

# every Shopify interaction, in every cassette the run opens; everything else replays
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify vendor/bin/phpunit

# only the Shopify interactions inside one cassette
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=sync/order-flow@shopify vendor/bin/phpunit

# everything the run opens, said out loud
VCR_ERASE_TAPE=all vendor/bin/phpunit
```

A cassette recorded from a test that talks to two APIs is refreshable one API at a time:
interactions belonging to other providers survive the truncation and keep replaying, so the
run needs credentials only for the API being refreshed.

## Matching

Which recorded interaction a request corresponds to is decided by a composable list of
matchers, all of which must agree. The default set is
`[MethodMatcher, UriMatcher, QueryStringMatcher]`.

```php
new VcrClient($inner, cassette: 'shopify/get-product', matchers: [
    new MethodMatcher(),
    new UriMatcher(),
    new QueryStringMatcher(),
    new HeadersMatcher(['X-Shop-Domain']),
]);
```

| Matcher | Compares |
|---|---|
| `MethodMatcher` | HTTP method, case-insensitively |
| `UriMatcher` | scheme + host + path, normalized |
| `HostMatcher` | host only, when matching the full path is too strict |
| `QueryStringMatcher` | query params as an unordered set; repeated keys keep their order |
| `HeadersMatcher` | named headers, subset match by default, `exact: true` for both directions |
| `BodyMatcher` | raw body, exact |
| `BodyJsonMatcher` | semantic JSON — key order doesn't matter, types are compared strictly |

Values that legitimately change every run are handled on the JSON matcher, since redaction
can't help with a value not known in advance:

```php
(new BodyJsonMatcher())
    ->ignoreJsonField('/transactionId')                  // any value counts as equal
    ->matchJsonField('/requestId', '/^[0-9a-f-]{36}$/'); // must look like a UUID
```

Writing your own means implementing one method — matchers compare two `RecordedRequest`
snapshots, not live PSR-7 objects, so no matcher can drain a stream out from under the next
one:

```php
interface RequestMatcherInterface {
    public function matches(RecordedRequest $recorded, RecordedRequest $incoming): bool;
}
```

## Redaction and secret scanning

`Authorization`, `Proxy-Authorization`, `Cookie` and `Set-Cookie` are redacted from the
first recording with nothing to configure. Anything else is opt-in:

```php
$vcr->redact('<SHOPIFY_API_KEY>', fn () => $_ENV['SHOPIFY_API_KEY']);

$vcr->redactHeader('X-Api-Key');
$vcr->redactJsonField('/customer/email');
$vcr->redactQueryParam('api_key');       // ?api_key=xxx in the URL itself
$vcr->redactFormField('client_secret');  // form-encoded body
```

Redaction is symmetric: the real value is replaced with the placeholder before anything
touches disk, and swapped back at replay time — on the recorded request before matching,
and on the recorded response before application code receives it. It covers everything
stored in an interaction, including the message of a recorded transport failure.

Both are built on a general hook pipeline (`beforeRecord` / `beforePlayback`) that's open
for anything else an interaction needs done to it on the way in or out.

On top of that, every session that records anything runs the new interactions through a
credential heuristic and warns:

```
http-vcr: recorded 1 interaction → tests/Cassettes/shopify/get-product.json
  response.body carries a credential-shaped value, stored unredacted:
    "sk_live_4eC39H…"
```

It never fails a test and never blocks the write — the point is to put the finding in front
of you while the context is fresh, before the file is committed. For the blocking version
across every cassette, with an exit code CI can act on, run `vendor/bin/http-vcr
scan-secrets`.

## More control

- **Strict mode** — `StrictMode::AllPlayed` fails when the cassette closes with an
  interaction nothing ever asked for; `StrictMode::InOrder` requires the recorded sequence
  to be replayed in order.
- **`staleAfter`** — flags interactions older than a `DateInterval`. Informational by
  default (`vendor/bin/http-vcr stale` as a non-blocking CI step), enforced per-run with
  `VCR_ENFORCE_STALE_CHECK=1`. "Now" comes from an injectable PSR-20 clock, and
  `FrozenClock` ships with the package so testing this needs no extra dependency.
- **Locked interactions** — the write-protect tab. `vendor/bin/http-vcr lock
  shopify/checkout --interaction=2` means that interaction never generates a real request
  again, above `VCR_ERASE_TAPE` and `VCR_ALLOW_RECORDING` both. For a request that charges a
  card or creates an order.
- **Scoping by URL** — `RegexUrlScopeResolver('#/api/(?<scope>\d{4}-\d{2})/#')` stores an
  API version in its own file (`get-product.2024-01.json`), so a version bump gives a clear
  "nothing recorded for this version" rather than a silent match against outdated data.
- **Transport errors** — opt in with `recordTransportErrors: true` to record a timeout or
  connection failure as a deterministic interaction, for testing retry logic. Replay throws
  `VcrNetworkException` / `VcrRequestException`, which implement the PSR-18 interfaces.
- **Repeatable playback** — an interaction that isn't consumed when replayed, for the
  target of a retry loop.

## Framework integration

| | How |
|---|---|
| **Any PSR-18 client** | `new VcrClient($client, cassette: '…')` — nothing else needed |
| **Guzzle** | `VcrMiddleware` on the `HandlerStack`, so `$client->get()` and friends are covered too — those bypass any decorator around the client |
| **Symfony** | `VcrHttpClient` implements `HttpClientInterface`, the one a Symfony app injects into services. `Psr18Client` needs no bridge |
| **PHPUnit** | `#[UseCassette]`, `#[CassetteDirectory]`, `InteractsWithCassettes`, `Extension`. PHPUnit 10–13 |
| **Laravel** | [`mtk3d/laravel-http-vcr`](https://github.com/mtk3d/laravel-http-vcr) — a separate package: auto-registered provider, `Http` facade interception, `artisan vcr:*`. Laravel 11+ |

Runnable versions of each are in [`examples/`](./examples).

## CLI

```bash
vendor/bin/http-vcr <command> [--config=path/to/http-vcr.php]
```

| Command | Does |
|---|---|
| `stale` | Lists interactions past their `staleAfter` |
| `tests` | Lists tests touching a provider, plus a ready-made `--filter` regex |
| `providers` | Every configured provider with its hosts, `requiresEnv` and cassette counts, then the hosts running unclaimed |
| `scan-secrets` | Credential scan across every cassette, with an exit code |
| `lock` / `unlock` | Sets or clears the write-protect flag on an interaction |

None of these run the test suite: `#[UseCassette]` is read by parsing the source, so no
command needs a correctly configured environment to answer a question about cassettes. That
also means anything not statically resolvable (`staleAfter: self::INTERVAL`, a computed
cassette name) is reported as "couldn't be fully analyzed" rather than guessed at.

The `tests` command exists to make a targeted re-record quick:

```bash
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify \
  vendor/bin/phpunit --filter "$(vendor/bin/http-vcr tests --provider=shopify --filter-only)"
```

The filter is only ever a speed optimization — what gets erased is decided by
`VCR_ERASE_TAPE`, so the same command without it produces the same cassettes, just slower.

## Configuration

Everything is optional; http-vcr works with none of it. Project-wide defaults go in an
`http-vcr.php` found by walking up from the working directory, no further than the
directory holding `composer.json`:

```php
<?php

use HttpVcr\Config;
use HttpVcr\Provider;

return Config::create(
    cassetteDirectory: __DIR__ . '/tests/Cassettes',
    providers: [
        'shopify' => new Provider(hosts: ['*.myshopify.com'], requiresEnv: ['SHOPIFY_API_KEY']),
    ],
    redact: ['<COMPANY_PROXY_TOKEN>' => fn () => $_ENV['COMPANY_PROXY_TOKEN']],
    innerClientFactory: fn () => new GuzzleHttp\Client(['timeout' => 30]),
);
```

A **provider** is a name for an external API: host patterns plus the environment variables
recording it requires. Naming one buys `VCR_ERASE_TAPE=@shopify` and a check that fails
before anything is recorded against a missing credential — `VCR_ERASE_TAPE=@api.stripe.com`
works against a bare hostname without any configuration either way.

The same object can be filled in from code instead, for projects that would rather
configure in a PHPUnit bootstrap than add a file:

```php
VcrClient::configure(cassetteDirectory: __DIR__ . '/Cassettes');
```

## Documentation

**[mtk3d.github.io/http-vcr](https://mtk3d.github.io/http-vcr/)** — the full book, covering
every option in reference tables, plus the parts this README only names: the cassette
format, the hook pipeline, storage backends and serializers, the environment-variable
precedence table, and every exception with the question it answers.

The source is in [`docs/`](./docs/src/SUMMARY.md); `mdbook serve docs` reads it locally.

[`PLAN.md`](./PLAN.md) carries the design decisions, each with the alternatives that were
rejected and why. It's written in Polish; everything else is in English.

## Dependencies

The record/replay core depends on `psr/http-message`, `psr/http-client`,
`psr/http-factory` and `psr/clock`, and nothing else — no Guzzle, no Symfony, no framework.
The package additionally requires `symfony/console` and `nikic/php-parser` for the CLI;
since http-vcr is installed as a dev dependency, neither reaches an application's
production autoloader.

Optional, install only if needed: `guzzlehttp/guzzle` (the middleware bridge),
`symfony/http-client` (the native `HttpClientInterface` bridge), `symfony/yaml` (the YAML
serializer), `phpunit/phpunit` (the attribute and trait).

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse            # level max
vendor/bin/pint --test                # Laravel Pint, laravel preset
```

All three run in CI across PHP 8.2–8.5. Conventions for working on this repository are in
[AGENTS.md](./AGENTS.md).

## License

MIT — see [LICENSE](./LICENSE).
