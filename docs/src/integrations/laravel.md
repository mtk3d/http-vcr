# Laravel

Laravel's `Http` facade is a thin wrapper around Guzzle — see [Guzzle](guzzle.md) for what that means for the decorator. So Guzzle handler-stack middleware covers every `Http::` call, and there are two ways to install it: the bridge package, which does it for the whole application, or the recipe, which does it by hand per test.

> **`mtk3d/laravel-http-vcr` is built but not published yet.** Until it is on Packagist, use the recipe below — it is the same interception, wired manually.

## The bridge package

```bash
composer require --dev mtk3d/laravel-http-vcr
```

It pulls in `mtk3d/http-vcr` itself, auto-registers through `extra.laravel.providers`, and requires **Laravel 12 or newer** (Laravel 13 needs PHP 8.3). Laravel 11 is not supported: every 11.x release is covered by a security advisory with no patched release, so Composer declines to install it.

With the [PHPUnit extension registered](phpunit.md#setup), that is the whole setup:

```php
#[UseCassette('shopify/get-product', requiresEnv: ['SHOPIFY_API_KEY'])]
public function testGetProduct(): void
{
    $product = Http::get('https://shop.myshopify.com/admin/api/2024-01/products/123.json')->json();

    $this->assertSame('T-Shirt', $product['title']);
}
```

No `Http::fake()`, no client constructed by hand — every `Http::` call is intercepted for the duration of the test, whichever method on the facade made it. A test without `#[UseCassette]` behaves exactly as if the package were not installed.

What the provider does:

- installs the middleware through `Illuminate\Http\Client\Factory::globalMiddleware()`, **appending** to whatever the application registered rather than replacing it, from a `booted()` callback so provider registration order does not matter
- registers the [CLI](../reference/cli.md) as Artisan commands, prefixed: `vcr:stale`, `vcr:providers`, `vcr:tests`, `vcr:scan-secrets`, `vcr:migrate`, `vcr:lock`, `vcr:unlock`
- narrows the default for `VCR_ALLOW_RECORDING` with `app()->environment()`: when the variable is not set explicitly, recording is allowed only if the environment is `local`/`testing` **and** the framework-agnostic [CI detection](../reference/environment.md) found nothing. It only ever tightens that default, never loosens it — an environment check on its own would be worse than useless, since tests on CI run with `APP_ENV=testing` and would end up *more* permissive than on a plain PHP project. An explicitly set variable still wins over both
- installs the HTTP hook only in `local` and `testing`. The package is a dev dependency and usually is not in production at all; this is the belt to that suspenders
- warns, once per run in `testing`, if http-vcr's [PHPUnit extension](phpunit.md#setup) is not registered. Your test calls the `Http` facade rather than `$this->vcrClient()`, so the trait's own guard never fires — without this check a missing extension would mean every `#[UseCassette]` test silently talking to the real API

It publishes no `config/http-vcr.php`. A Laravel application configures http-vcr through the same root-level `http-vcr.php` as any other project, and needs nothing at all for the common case — see [Configuration](../reference/configuration.md#laravel).

> **Why not `globalOptions(['handler' => ...])`?** It looks like the obvious hook and is inert: `PendingRequest::createClient()` hands its own stack to the Guzzle client as a constructor option, and `handler` is not a per-request option, so a handler set that way is never consulted. `globalMiddleware()` takes raw handler-stack middleware, which is what can short-circuit a call.

> **Why not `globalRequestMiddleware()`?** Laravel also exposes `globalRequestMiddleware()` / `globalResponseMiddleware()`, which are *transformers*: one takes a request and must return a request, the other takes a response and must return a response. Neither can short-circuit a call and serve a response from a cassette instead of going to the network — the one thing a VCR needs from a hook. Handler-stack middleware can, which is why `Http::fake()` uses the same mechanism.

## `Http::assertSent()` on a replayed call

Laravel pushes its own handlers *after* global middleware, which puts the recorder behind `Http::assertSent()` **inside** the cassette middleware — so a replayed call short-circuits above it. The bridge reports the pair to that recorder itself when it replays, because `assertSent()` asks what the application did and a replayed call is something the application did.

One line is still needed, and it is Laravel's own:

```php
#[UseCassette('shopify/get-product')]
public function testItAsksForTheRightProduct(): void
{
    Http::record();

    (new Shopify)->product('123');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/123'));
}
```

`Http::record()` switches on the recorder those assertions read. It is normally turned on as a side effect of `Http::fake()`, which a cassette replaces.

## Without the package: the manual recipe

```php
// in a test's setUp(), or a testing-only service provider
Http::globalMiddleware(VcrMiddleware::create($vcr));
```

Same interception, no additional dependency — but it is configuration you wire by hand in every test or in a testing-only service provider, and tearing it down again is yours to do. The whole thing, as a base test case to copy, is in [`examples/laravel-http-recipe.php`](https://github.com/mtk3d/http-vcr/blob/master/examples/laravel-http-recipe.php).

## Who installs the hook, and when

This applies to the recipe as much as to the package: the PHPUnit extension cannot be the thing that installs it. Its hook fires *before* `setUp()`, and a Laravel application is created *inside* `setUp()` (`TestCase::createApplication()`) and destroyed again in `tearDown()`, so anything the extension set on `Factory` would land on the previous test's container, or on one that does not exist yet. The work splits in two:

- **The PHPUnit extension** builds the test's `VcrClient` and puts it in a process-level handle (`CurrentCassetteSession` — the same one `$this->vcrClient()` reads from), then closes the cassette and clears the handle afterwards. It knows nothing about Laravel; this is the same path Guzzle and Symfony use.
- **Whatever installs the middleware** — your `setUp()`, a testing-only service provider, or the bridge's own provider — does it once per application boot. The middleware consults the handle **at request time**: a cassette session is active, so the request goes through it; no session, so the request passes straight to the next handler untouched.

That handle is process-level state, which the [core deliberately avoids](../concepts/how-it-works.md#no-global-state). It lives in the bridge, not the core, and it is forced by what is being intercepted: `Http` is a facade — a global service locator — so the only way to take over a call without touching the call site is a pointer to "the currently active session." The same hook that opens and closes the cassette sets and clears it, so it cannot leak between tests.
