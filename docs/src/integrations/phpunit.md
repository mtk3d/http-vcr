# PHPUnit

## Setup

Register the extension in `phpunit.xml`, once:

```xml
<extensions>
    <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
</extensions>
```

PHPUnit has no auto-discovery for extensions, so this is the one step that can't happen by itself — and skipping it fails quietly in the worst way: `#[UseCassette]` becomes decoration, nobody reads it, and the test makes real network calls. `$this->vcrClient()` guards against exactly that: with no extension registered there's no active session, and it throws telling you to add the block above rather than handing back an unconfigured client.

Nothing else is required. The config file, the cassette directory and the PSR-17 factory all have working defaults.

## The attribute

```php
use HttpVcr\Bridge\PHPUnit\UseCassette;

#[UseCassette(
    'zendesk-account-a/get-ticket',
    mode: RecordMode::RecordIfAbsent,
    requiresEnv: ['ZENDESK_ACCOUNT_A_SUBDOMAIN'], // optional — on top of the provider's own, see Providers
    locked: true,                        // optional — see Locked Interactions
    strictMode: StrictMode::AllPlayed,   // optional, defaults to StrictMode::None — see Strict & Sequential Mode
    staleAfter: Stale::Week,             // optional, defaults to null — see Auto Re-record
)]
public function testGetTicket(): void { /* ... */ }
```

On a test method or class, PHPUnit's Extension API registers a hook that creates a configured `VcrClient` before the test, publishes it for the trait to hand out, closes the cassette after the test, and — under `StrictMode::AllPlayed` — asserts everything got replayed.

The attribute needs the Extension API, so the bridge supports **PHPUnit 10 through 13**. (That's a different range from what http-vcr itself is tested on — see [Installation](../getting-started/installation.md).) On PHPUnit 9 and older there are no such hooks; use the trait's closure form below instead.

On a class, `#[UseCassette]` is sugar for applying the identical attribute to every method in that class — not a shared session. Each method still gets its own independent `VcrClient` (own open/close, own replay-consumption tracking) against the same cassette *file*. That's useful when several methods deliberately exercise the same recording (e.g. proving two client bridges behave identically against one cassette), not a way to spread unrelated requests across methods sharing one file — a method that doesn't replay everything in the cassette will trip `StrictMode::AllPlayed` just as it would with any other session. A method-level attribute replaces the class-level one entirely rather than merging with it, matching how PHPUnit's own attributes behave.

`VcrClient` is a mutable configuration object up until the first `sendRequest()` of the cassette session — `redact()` and friends, `includeSensitiveHeaders()`, `beforeRecord()`/`beforePlayback()`, and any matcher added outside the constructor must be registered before that point, or they throw `LogicException` (see [VcrClient Reference](../reference/vcr-client.md#configuration-is-frozen-after-the-first-request)). In practice: `$this->vcrClient()` is already available and unfrozen by the time `setUp()` runs, since the extension's hook fires before it — so `setUp()` (or the first lines of the test body, before any code that triggers a request) is the right place for per-test redaction.

`strictMode` and `staleAfter` are both set per test here, not globally — that's usually what's wanted: `AllPlayed`/`InOrder` tend to matter for one specific, well-understood action rather than the whole suite, and different integrations often go stale at different rates. One difference between the two: `stale` (see [CLI Reference](../reference/cli.md)) needs to know each cassette's `staleAfter` threshold without running any tests, so it's read from the attribute via the same AST scan `tests` uses — `strictMode` has no such requirement, since it only matters while the test itself is running.

## The trait

`InteractsWithCassettes` has two methods, for two different situations — the first one is used *together with* the attribute, not instead of it:

```php
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;

// the VcrClient the attribute built for this test: the PSR-18 client to pass
// into the code under test, and what per-test redaction is registered on
$vcr = $this->vcrClient();

// a cassette session around a closure, with no attribute involved
$this->useCassette('shopify/get-product', function () {
    // ...
});
```

`useCassette()` is for PHPUnit 9 and older (no Extension API), for a test that needs two different cassettes, and for tests not written in PHPUnit at all. It accepts the same optional arguments as the attribute: `mode`, `strictMode`, `staleAfter`, `requiresEnv`, `locked`. The closure is handed the `VcrClient` as its argument, and whatever it returns comes back from `useCassette()`.

The trait also closes the session, from an `#[After]` method it brings with it. That matters for one thing: `StrictMode::AllPlayed`/`InOrder` assert at close, and an assertion raised inside the test's own lifecycle fails *that test*, while an exception from a PHPUnit event subscriber only ever becomes a runner warning. A test class that declares a cassette without using the trait still gets its lock released and its session cleared by the extension — it just won't fail on a strict-mode violation.

Both read from `HttpVcr\Bridge\PHPUnit\CurrentCassetteSession`, the process-level handle the extension puts the test's `VcrClient` into and clears afterwards. It's a **public, BC-guaranteed contract**, not an internal detail — the [Laravel bridge](laravel.md#who-installs-the-hook-and-when) lives in a separate package and consults it at request time to decide whether an `Http` facade call belongs to an active cassette session. Anything else integrating a framework whose HTTP entry point is global will need the same seam.

## Providers

A provider is an **external API you can name in a command** — most usefully in `VCR_ERASE_TAPE=@shopify`, to refresh one API's recordings and leave the rest alone.

**You get providers without configuring anything.** Every host that appears in your cassettes is implicitly its own provider, named after the host, so this works in a project with no `http-vcr.php` at all:

```bash
VCR_ERASE_TAPE=@shop.myshopify.com vendor/bin/phpunit
```

Declaring providers explicitly is an upgrade on top of that, not a prerequisite for it:

```php
return HttpVcr\Config::create(
    providers: [
        'shopify'   => new Provider(hosts: ['*.myshopify.com'],       requiresEnv: ['SHOPIFY_API_KEY']),
        'zendesk-a' => new Provider(hosts: ['account-a.zendesk.com'], requiresEnv: ['ZENDESK_A_API_KEY']),
        'zendesk-b' => new Provider(hosts: ['account-b.zendesk.com'], requiresEnv: ['ZENDESK_B_API_KEY']),
    ],
);
```

A declared provider buys you three things an implicit one can't have:

1. **`requiresEnv`** — credential pre-validation scoped to that API (below). This can't be inferred from a host; it's the main reason to declare anything at all.
2. **A shorter, more durable name** — `@shopify` instead of `@shop.myshopify.com`, and it survives the domain changing.
3. **Several hosts as one API** — `*.myshopify.com` together with `shopify.dev`, refreshed as a unit.

Either way, the payoff is the same: [scoping a re-record to one API](../reference/environment.md#vcr_erase_tape-selectors), including inside a cassette that also talks to others.

An interaction belongs to a provider if its request host matches one of the provider's patterns. Matching is on the host alone — no scheme, port, or path — case-insensitively, glob-style: `*.myshopify.com` covers any subdomain, `account-a.zendesk.com` only that exact host. Nothing about this is written into the cassette; it's derived at use time from the current configuration, so changing a pattern applies retroactively to everything already recorded and there's no stored field to drift out of sync.

Resolution for `@name`, in order: a configured provider with that name, then an exact host seen in the cassettes. Globs are only available to declared providers — `*.myshopify.com` is a judgement about what counts as one API, not a fact readable from the data. A host claimed by a declared provider stops being addressable by itself, so there's exactly one spelling for one thing. A name matching neither erases nothing: which hosts a project's cassettes actually contain is a question only [`http-vcr providers`](../reference/cli.md#providers) can answer, since it is the one thing that reads all of them.

Two providers matching the same host is a configuration error too, reported when `http-vcr.php` loads rather than resolved by declaration order. [`vendor/bin/http-vcr providers`](../reference/cli.md#providers) lists which hosts are running on implicit providers — a ready-made shortlist of what's worth naming.

There's no imposed structure: http-vcr doesn't distinguish "platform" from "account" or "instance." Two Zendesk accounts on separate subdomains are simply two providers — and, unlike a label declared in the test, that separation is enforced by the host itself and can't be broken by a typo in an attribute.

## Pre-validating environment variables

When a request is about to be recorded for real, and a required environment variable is empty, `MissingEnvironmentVariableException` is thrown **before** the request goes out:

```
Cannot record cassette "shopify/get-product": missing env var SHOPIFY_API_KEY
(required by provider "shopify").
```

— instead of a confusing 401 partway through the test. Two sources are consulted:

- **The provider's `requiresEnv`**, matched against the host of that specific request. This is where API keys belong.
- **The cassette's `requiresEnv`** (the attribute, or the `VcrClient` constructor), for variables that aren't tied to a host — and the only option at all in a project with no `http-vcr.php`.

Anything with no `requiresEnv` declared simply isn't checked; it's opt-in, not a requirement. When both a provider and the cassette are missing something, one exception names both.

Two things about the timing matter. It fires on the recording branch, not at the start of the test — recording is allowed by default on a developer machine, so validating up front would mean every *replaying* test there demanded a full set of real credentials it was never going to use. And it's evaluated **per request**, not per session, which is what lets `VCR_ERASE_TAPE=@shopify` refresh the Shopify half of a Shopify→Zendesk cassette while asking only for `SHOPIFY_API_KEY`.

## Selecting VCR tests: no automatic groups

`#[UseCassette]` does not give your tests a `vcr` or `vcr:shopify` group, so `--group vcr:shopify` won't select anything unless you add `#[Group(...)]` yourself. Two reasons, and the second matters more than the first.

It isn't possible: PHPUnit builds group metadata exclusively from its own attributes, and group filtering happens while the suite is being built — before any Extension API hook runs. A third-party attribute has no way in.

And it wouldn't be the right tool anyway. A group selects **tests**, while refreshing a recording is about **interactions**: a test that talks to two APIs would still need credentials for both and would still re-record traffic that was perfectly fine. What you use instead is split in two:

- **Correctness** comes from the [`VCR_ERASE_TAPE` selector](../reference/environment.md#vcr_erase_tape-selectors) — it, not a test filter, decides what gets erased and re-recorded. A run with no filter at all produces the same cassettes.
- **Convenience** comes from [`http-vcr tests --provider=…`](../reference/cli.md#tests), which prints a ready-made `--filter` regex so you can skip tests that can't be affected.

And if you do want groups — to exclude VCR tests from a fast unit-test run, say — add `#[Group(...)]` to your tests yourself, exactly as you would for any other grouping.

## Project configuration

An optional `http-vcr.php` in the project root — everything has sane defaults without it:

```php
return HttpVcr\Config::create(
    cassetteDirectory: __DIR__ . '/tests/Cassettes',
    testDirectories: [__DIR__ . '/tests'],
    persister: new FilesystemCassettePersister(),
    serializer: new JsonCassetteSerializer(),
    defaultMatchers: [new MethodMatcher(), new UriMatcher(), new QueryStringMatcher()],
);
```

This is the single place both the `#[UseCassette]` attribute and the CLI (`stale`, `providers`, `scan-secrets`) look to find cassettes and test files in the project. Every argument is optional; the full list, including the `innerClientFactory` the attribute uses to build a real client when it needs to record, is in the [Configuration Reference](../reference/configuration.md).

Without the file, `cassetteDirectory` defaults to `tests/Cassettes/` relative to the project root (the directory holding `composer.json`), and the cassette name is a path inside it: `shopify/get-product` → `tests/Cassettes/shopify/get-product.yaml`. That default is the same whether the client comes from `#[UseCassette]`, from `new VcrClient(...)` in a script, or from the CLI. To put one part of the suite's cassettes somewhere else, see [Cassettes somewhere other than the project root](#cassettes-somewhere-other-than-the-project-root).

`http-vcr.php` is discovered automatically: the search starts in the current working directory and walks upward, stopping at the first `http-vcr.php` it finds — or, if none turns up, at the directory containing `composer.json` (never past it, so a shared CI runner or a monorepo can't accidentally pick up an unrelated file further up the tree). No file found simply means the defaults apply; it's optional. For a non-standard layout, bypass discovery entirely with an explicit `VcrClient::configure(...)` call in the PHPUnit bootstrap, or `vendor/bin/http-vcr --config=<path>` for the CLI.

A `redact` option is also available here, for a secret shared across *every* cassette in the project (say, a company-wide proxy token) — see [Redacting Sensitive Data](../safety/redaction.md#project-wide-redaction).

## Cassettes somewhere other than the project root

In a modular monolith, a module's cassettes usually belong with the module rather than in one pile under `tests/Cassettes/`. `#[CassetteDirectory]` says so, once, on the module's base test case:

```php
use HttpVcr\Bridge\PHPUnit\CassetteDirectory;

#[CassetteDirectory(__DIR__ . '/Cassettes')]
abstract class BillingTestCase extends TestCase
{
    use InteractsWithCassettes;
}
```

```php
final class ChargeTest extends BillingTestCase
{
    #[UseCassette('stripe/charge')]   // → modules/Billing/tests/Cassettes/stripe/charge.yaml
    public function testCharge(): void { /* ... */ }
}
```

`__DIR__` works because attribute arguments are constant expressions, so the path is written where you can see it and resolves relative to the file it's written in. The attribute is looked up on the test class and then up its parent chain, first one found wins — declare it once per module, not once per test class.

Cassette names are unaffected: `stripe/charge` is still just a path inside whichever directory applies. Nothing is routed by name.

Two limits worth knowing:

- **It only covers the PHPUnit path.** A hand-built `VcrClient` elsewhere takes a `persister` argument instead.
- **The CLI resolves it by parsing, not executing.** `stale`, `tests` and `scan-secrets` read the attribute from the test files' syntax tree, following `extends` across every `.php` file under `testDirectories`. A base class outside those directories is never parsed, so a `#[UseCassette]` or `#[CassetteDirectory]` written on one is invisible to the CLI while still working at run time — keep shared declarations on a base class that lives under `testDirectories`. An argument the parser can't evaluate (`staleAfter: self::INTERVAL`) is reported as not fully analyzed rather than guessed at. It is also why the named intervals are enum cases: `Stale::Week` is a constant expression, which both PHP attributes and this scan can resolve, and a factory call is neither.

## Environment variables

Four variables — `VCR_ALLOW_RECORDING`, `VCR_ERASE_TAPE`, `VCR_ENFORCE_STALE_CHECK`, `VCR_IGNORE_STALE_CASSETTES` — control recording and staleness from outside the test code, with locked interactions outranking all of them. They aren't PHPUnit-specific, so they live in one place: the [Environment Variables reference](../reference/environment.md), which also covers precedence, conflict resolution, and how the default for `VCR_ALLOW_RECORDING` is derived when it isn't set.

## A worked file

The attribute in its usual forms — on a method, on a class, with every parameter, and with a `#[CassetteDirectory]` base case — is in [`examples/phpunit-attribute.php`](https://github.com/mtk3d/http-vcr/blob/master/examples/phpunit-attribute.php).
