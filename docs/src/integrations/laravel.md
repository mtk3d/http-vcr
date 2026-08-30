# Laravel

Laravel's `Http` facade is a thin wrapper around Guzzle — see [Guzzle](guzzle.md) for what that means for the decorator. So `VcrMiddleware` on a `HandlerStack` covers every `Http::` call, and the recipe below is all it takes.

> **A `mtk3d/laravel-http-vcr` package is in progress and not released.** It will do the same with nothing to wire up. Until it is published, the recipe on this page is how http-vcr is used in a Laravel application; what the package is going to do is described further down, because the design is what the recipe is a hand-rolled version of.

## The recipe

```php
// in a test's setUp(), or a testing-only service provider
Http::globalOptions(['handler' => $stack]);
```

where `$stack` is a Guzzle `HandlerStack` with `VcrMiddleware::create($vcr)` pushed onto it (see [Guzzle](guzzle.md)). `globalOptions()` applies to **every** request made through the facade, regardless of call site. The whole thing, as a base test case to copy, is in [`examples/laravel-http-recipe.php`](https://github.com/mtk3d/http-vcr/blob/master/examples/laravel-http-recipe.php).

It is configuration you wire by hand in each test or in a testing-only service provider, and tearing it down again is yours to do.

> `Http::withOptions([...])` looks like it would do the same thing and doesn't: it returns a `PendingRequest` configured for **one** call, so as a standalone statement it configures an object that's then thrown away. Use it only when you go on to make the request on it (`Http::withOptions([...])->get(...)`). The application-wide form is `Http::globalOptions()`, added in Laravel 11.

> **Why not `globalRequestMiddleware()`?** Laravel also exposes `globalRequestMiddleware()` / `globalResponseMiddleware()`, which look like the obvious hook and aren't: they're *transformers*. One takes a request and must return a request, the other takes a response and must return a response. Neither can short-circuit a call and serve a response from a cassette instead of going to the network — the one thing a VCR needs from a hook. Handler-stack middleware can, which is why `Http::fake()` uses the same mechanism.

## What the bridge package will do

Planned, not released. It will require **Laravel 11 or newer**, pull in `mtk3d/http-vcr` itself, auto-register through `extra.laravel.providers`, and:

- publish `config/http-vcr.php` (`cassetteDirectory` defaulting to `base_path('tests/Cassettes')`)
- register the same commands as `vendor/bin/http-vcr` as Artisan commands, prefixed with `vcr:` (see [CLI Reference](../reference/cli.md) for why): `vcr:stale`, `vcr:providers`, `vcr:tests`, `vcr:scan-secrets`, `vcr:lock` / `vcr:unlock`
- install the `HandlerStack` through `Illuminate\Http\Client\Factory::globalOptions()` — the recipe above, done once at boot. If the application already sets its own `handler`, the bridge pushes onto that stack instead of replacing it (from a `booted()` callback, so it works regardless of provider registration order)
- narrow the default for `VCR_ALLOW_RECORDING` with `app()->environment()`: when the variable isn't set explicitly, recording is allowed only if the environment is `local`/`testing` **and** the framework-agnostic [CI detection](../reference/environment.md) found nothing. The bridge would only ever tighten that default, never loosen it — an environment check on its own would be worse than useless here, since tests on CI run with `APP_ENV=testing` and would end up *more* permissive than on a plain PHP project. An explicitly set variable still wins over both
- install the global hook only in the `local` and `testing` environments. The package is a dev dependency, so it usually isn't present in production at all — this is the belt to that suspenders
- warn, in the `testing` environment, if http-vcr's [PHPUnit extension](phpunit.md#setup) isn't registered in `phpunit.xml`. The test calls the `Http` facade rather than `$this->vcrClient()`, so the trait's own guard never runs — without this check a missing extension would mean every `#[UseCassette]` test silently talking to the real API

The test it is meant to make possible:

```php
#[UseCassette('shopify/get-product', requiresEnv: ['SHOPIFY_API_KEY'])]
public function testGetProduct(): void
{
    $product = Http::get('https://shop.myshopify.com/admin/api/2024-01/products/123.json')->json();

    $this->assertSame('T-Shirt', $product['title']);
}
```

## Who installs the hook, and when

This applies to the recipe as much as to the package: the PHPUnit extension cannot be the thing that installs it. Its hook fires *before* `setUp()`, and a Laravel application is created *inside* `setUp()` (`TestCase::createApplication()`) and destroyed again in `tearDown()`, so anything the extension set on `Factory` would land on the previous test's container, or on one that doesn't exist yet. The work splits in two:

- **The PHPUnit extension** builds the test's `VcrClient` and puts it in a process-level handle (`CurrentCassetteSession` — the same one `$this->vcrClient()` reads from), then closes the cassette and clears the handle afterwards. It knows nothing about Laravel; this is the same path Guzzle and Symfony use.
- **Whatever installs the handler** — your `setUp()`, a testing-only service provider, or the bridge's own — does it once per application boot. The `VcrMiddleware` consults the handle **at request time**: a cassette session is active, so the request goes through it; no session, so the request passes straight to the next handler untouched. A test without `#[UseCassette]` behaves exactly as if nothing were installed.

That handle is process-level state, which the [core deliberately avoids](../concepts/how-it-works.md#no-global-state). It lives in the bridge, not the core, and it's forced by what's being intercepted: `Http` is a facade — a global service locator — so the only way to take over a call without touching the call site is a pointer to "the currently active session." The same hook that opens and closes the cassette sets and clears it, so it can't leak between tests.
