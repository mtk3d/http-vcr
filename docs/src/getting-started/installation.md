# Installation

```bash
composer require --dev mtk3d/http-vcr
```

You need two things:

- **PHP 8.2 or newer.**
- **An HTTP client to wrap** — whichever one your project already uses. Guzzle 7+, Symfony's `Psr18Client`, php-http, Buzz: all of them work as they are, because all of them implement `Psr\Http\Client\ClientInterface`, which is the one thing http-vcr wraps.

That's the whole list. To hand a recorded response back to your code, http-vcr also needs a factory that builds response objects — but it takes that from the HTTP client library you already have (Guzzle ships one, Symfony's client pulls one in, and so on), so there is nothing extra to install, register, or pass in. In the unlikely case that nothing usable is found, http-vcr stops before the first request rather than partway through one, and `composer require --dev nyholm/psr7` settles it. If you'd rather supply your own, see the [VcrClient reference](../reference/vcr-client.md#psr-17-factories).

The record/replay core depends only on the PSR interfaces themselves (`psr/http-message`, `psr/http-client`, `psr/http-factory`, `psr/clock`) — no Guzzle, no Symfony, no framework. The package also pulls in `symfony/console` and `nikic/php-parser`, used exclusively by the [CLI](../reference/cli.md); since http-vcr is a dev dependency, those never reach a production autoloader.

## Optional pieces

Install these only if needed:

| Package | Needed for |
|---|---|
| `guzzlehttp/guzzle` | The `VcrMiddleware` bridge for a Guzzle `HandlerStack` — plain `GuzzleHttp\Client` works without it |
| `symfony/http-client` | The `VcrHttpClient` bridge for Symfony's native `HttpClientInterface` — `Psr18Client` works without it |
| `symfony/yaml` | Makes [YAML the cassette format](../advanced/storage-and-formats.md#serializers) instead of JSON. Already present in most Laravel and Symfony projects, in which case it is what you get |
| `phpunit/phpunit` | The [`#[UseCassette]` attribute and `InteractsWithCassettes` trait](../integrations/phpunit.md). The attribute is built on the Extension API, so the bridge supports **PHPUnit 10 through 13** — http-vcr's own test suite runs on 11.5–13, but that's a separate matter from what the bridge supports in your project |
| — | Laravel needs no extra package: `Http::` goes through Guzzle, so the [recipe on the Laravel page](../integrations/laravel.md) covers it. A `mtk3d/laravel-http-vcr` package that does the same with no wiring is in progress and not released |

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

By default, `tests/Cassettes/` relative to the project root (the directory containing `composer.json`), with the cassette name as a path inside it — `shopify/get-product` becomes `tests/Cassettes/shopify/get-product.yaml`, or `.json` where `symfony/yaml` isn't installed. Change the directory with `cassetteDirectory` in [`http-vcr.php`](../integrations/phpunit.md#project-configuration), and the format with [`serializer`](../advanced/storage-and-formats.md#serializers). To keep a module's recordings beside the module instead, see [Cassettes somewhere other than the project root](../integrations/phpunit.md#cassettes-somewhere-other-than-the-project-root).

Cassettes are meant to be committed. The lock files http-vcr uses while recording are not, and take no setup either way: they go in a `.http-vcr/` directory inside the cassette directory, which ignores itself.
