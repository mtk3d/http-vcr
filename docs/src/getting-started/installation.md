# Installation

```bash
composer require --dev mtk3d/http-vcr
```

You need two things:

- **PHP 8.2 or newer.**
- **An HTTP client to wrap** — whichever one your project already uses. Guzzle 7+, Symfony's `Psr18Client`, php-http, Buzz: all of them work as they are, because all of them implement `Psr\Http\Client\ClientInterface`, which is the one thing http-vcr wraps.

That's the whole list. To hand a recorded response back to your code, http-vcr also needs a factory that builds response objects — but it takes that from the HTTP client library you already have (Guzzle ships one, Symfony's client pulls one in, and so on), so there is nothing extra to install, register, or pass in. In the unlikely case that nothing usable is found, http-vcr stops before the first request rather than partway through one, and `composer require --dev nyholm/psr7` settles it. If you'd rather supply your own, see the [VcrClient reference](../reference/vcr-client.md#psr-17-factories).

The record/replay core depends only on the PSR interfaces themselves (`psr/http-message`, `psr/http-client`, `psr/http-factory`) — no Guzzle, no Symfony, no framework. The package also pulls in `symfony/console` and `nikic/php-parser`, used exclusively by the [CLI](../reference/cli.md); since http-vcr is a dev dependency, those never reach a production autoloader.

## Optional pieces

Install these only if needed:

| Package | Needed for |
|---|---|
| `guzzlehttp/guzzle` | The `VcrMiddleware` bridge for a Guzzle `HandlerStack` — plain `GuzzleHttp\Client` works without it |
| `symfony/http-client` | The `VcrHttpClient` bridge for Symfony's native `HttpClientInterface` — `Psr18Client` works without it |
| `symfony/yaml` | The [YAML cassette serializer](../advanced/storage-and-formats.md#serializers), for teams that would rather not use the default JSON one |
| `phpunit/phpunit` | The [`#[UseCassette]` attribute and `InteractsWithCassettes` trait](../integrations/phpunit.md). The attribute is built on the Extension API, so the bridge supports **PHPUnit 10 through 13** — http-vcr's own test suite runs on 11.5–13, but that's a separate matter from what the bridge supports in your project |
| `mtk3d/laravel-http-vcr` | Zero-setup use in a Laravel app — auto-registered service provider, `Http` facade interception, `artisan vcr:*` commands. A [separate package](../integrations/laravel.md) that depends on this one; needs **Laravel 11 or newer** |

See [Framework Integration](../integrations/guzzle.md) for details on each.

## Using it with PHPUnit

One line in `phpunit.xml` registers the extension that powers `#[UseCassette]`:

```xml
<extensions>
    <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
</extensions>
```

PHPUnit doesn't discover extensions on its own, so without this the attribute has no effect at all — see [PHPUnit Integration](../integrations/phpunit.md#setup).

## The CLI

Composer links the CLI into the consuming project's `vendor/bin`:

```bash
vendor/bin/http-vcr providers
```

In a Laravel app the same commands are also available through `artisan vcr:*` — see the [CLI Reference](../reference/cli.md).

## Where cassettes go

By default, `tests/Cassettes/` relative to the project root (the directory containing `composer.json`), with the cassette name as a path inside it — `shopify/get-product` becomes `tests/Cassettes/shopify/get-product.json`. Change it with `cassetteDirectory` in [`http-vcr.php`](../integrations/phpunit.md#project-configuration).

Cassettes are meant to be committed. The lock files http-vcr creates next to them while recording are not — add `*.cassette-lock` to `.gitignore`.
