# Laravel

Laravel's `Http` facade is a thin wrapper around Guzzle — see [Guzzle](guzzle.md) for what that means for the decorator. Install the bridge package and every `Http::` call in a test with `#[UseCassette]` is recorded and replayed, with nothing to wire up. If you'd rather not add a package, there's a manual recipe at the bottom of this page.

## The bridge package

For the same zero-ceremony feel as Laravel's built-in `Http::fake()`, install the Laravel bridge — a separate package that depends on http-vcr:

```bash
composer require --dev mtk3d/laravel-http-vcr
```

It pulls in `mtk3d/http-vcr` itself, so that's the only line you need. It requires **Laravel 11 or newer** — expressed in the package's own `require`, so Composer declines to install it on anything older rather than letting it fail later.

The package auto-registers itself (`extra.laravel.providers` in its `composer.json` — no manual entry in `bootstrap/providers.php`), and:

- publishes `config/http-vcr.php` (`cassetteDirectory` defaults to `base_path('tests/Cassettes')`)
- registers the same commands as `vendor/bin/http-vcr` as Artisan commands, prefixed with `vcr:` (see [CLI Reference](../reference/cli.md) for why): `vcr:stale`, `vcr:providers`, `vcr:tests`, `vcr:scan-secrets`, `vcr:lock` / `vcr:unlock`
- hooks into `Illuminate\Http\Client\Factory::globalOptions()` — Laravel's own public API for setting Guzzle options on **every** request made through the `Http` facade, regardless of call site — to install a `HandlerStack` carrying `VcrMiddleware`. If the application already sets its own `handler`, the bridge pushes onto that stack instead of replacing it (from a `booted()` callback, so it works regardless of provider registration order)
- narrows the default for `VCR_ALLOW_RECORDING` with `app()->environment()`: when the variable isn't set explicitly, recording is allowed only if the environment is `local`/`testing` **and** the framework-agnostic [CI detection](../reference/environment.md) found nothing. The bridge only ever tightens that default, never loosens it — an environment check on its own would be worse than useless here, since tests on CI run with `APP_ENV=testing` and would end up *more* permissive than on a plain PHP project. An explicitly set variable still wins over both
- only installs the global hook in the `local` and `testing` environments. The package is a dev dependency, so it usually isn't present in production at all — this is the belt to that suspenders
- warns, in the `testing` environment, if http-vcr's [PHPUnit extension](phpunit.md#setup) isn't registered in `phpunit.xml`. Your test calls the `Http` facade rather than `$this->vcrClient()`, so the trait's own guard never runs — without this check a missing extension would mean every `#[UseCassette]` test silently talking to the real API

```php
#[UseCassette('shopify/get-product', requiresEnv: ['SHOPIFY_API_KEY'])]
public function testGetProduct(): void
{
    $product = Http::get('https://shop.myshopify.com/admin/api/2024-01/products/123.json')->json();

    $this->assertSame('T-Shirt', $product['title']);
}
```

No `Http::fake()`, no manual client construction — every `Http::` call is intercepted for the duration of the test, no matter which method on the facade is used.

## Who installs the hook, and when

Not the PHPUnit extension — it can't. Its hook fires *before* `setUp()`, and a Laravel application is created *inside* `setUp()` (`TestCase::createApplication()`) and destroyed again in `tearDown()`, so anything the extension set on `Factory` would land on the previous test's container, or on one that doesn't exist yet. The work is split instead:

- **The PHPUnit extension** builds the test's `VcrClient` and puts it in a process-level handle (`CurrentCassetteSession` — the same one `$this->vcrClient()` reads from), then closes the cassette and clears the handle afterwards. It knows nothing about Laravel; this is the same path Guzzle and Symfony use.
- **`HttpVcrServiceProvider`** installs the `globalOptions()` handler once per application boot — which, in tests, means inside each `setUp()`. The `VcrMiddleware` it installs consults the handle **at request time**: a cassette session is active, so the request goes through it; no session, so the request passes straight to the next handler untouched. A test without `#[UseCassette]` behaves exactly as if the bridge weren't installed.

That handle is process-level state, which the [core deliberately avoids](../concepts/how-it-works.md#no-global-state). It lives in the bridge, not the core, and it's forced by what's being intercepted: `Http` is a facade — a global service locator — so the only way to take over a call without touching the call site is a pointer to "the currently active session." The same hook that opens and closes the cassette sets and clears it, so it can't leak between tests.

> **Why not `globalRequestMiddleware()`?** Laravel also exposes `globalRequestMiddleware()` / `globalResponseMiddleware()`, which look like the obvious hook and aren't: they're *transformers*. One takes a request and must return a request, the other takes a response and must return a response. Neither can short-circuit a call and serve a response from a cassette instead of going to the network — the one thing a VCR needs from a hook. Handler-stack middleware can, which is why `Http::fake()` uses the same mechanism.

## Without the package: the manual recipe

```php
// in a test's setUp(), or a testing-only service provider
Http::globalOptions(['handler' => $stack]);
```

where `$stack` is a Guzzle `HandlerStack` with `VcrMiddleware::create($vcr)` pushed onto it (see [Guzzle](guzzle.md)). Same interception, zero additional dependencies — but it's configuration you wire by hand in every test or in a testing-only service provider, and you're responsible for tearing it down again.

> `Http::withOptions([...])` looks like it would do the same thing and doesn't: it returns a `PendingRequest` configured for **one** call, so as a standalone statement it configures an object that's then thrown away. Use it only when you go on to make the request on it (`Http::withOptions([...])->get(...)`). The application-wide form is `Http::globalOptions()`, added in Laravel 11.
