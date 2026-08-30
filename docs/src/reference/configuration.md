# Configuration Reference

Project-wide defaults live in one configuration object, reachable two equivalent ways: declaratively through an `http-vcr.php` file, or imperatively through `VcrClient::configure()`. Everything in it is optional — http-vcr works with no configuration at all.

## `http-vcr.php`

```php
<?php

use HttpVcr\Config;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\UriMatcher;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Serializer\JsonCassetteSerializer;

return Config::create(
    cassetteDirectory: __DIR__ . '/tests/Cassettes',
    testDirectories: [__DIR__ . '/tests'],
    providers: [
        'shopify' => new Provider(hosts: ['*.myshopify.com'], requiresEnv: ['SHOPIFY_API_KEY']),
    ],
    persister: new FilesystemCassettePersister(),
    serializer: new JsonCassetteSerializer(),
    defaultMatchers: [new MethodMatcher(), new UriMatcher(), new QueryStringMatcher()],
    redact: ['<COMPANY_PROXY_TOKEN>' => fn () => $_ENV['COMPANY_PROXY_TOKEN']],
    innerClientFactory: fn () => new GuzzleHttp\Client(['timeout' => 30]),
);
```

| Option | Default | What it does |
|---|---|---|
| `cassetteDirectory` | `tests/Cassettes/` under the project root | Where cassettes live. A cassette name is a path inside it. |
| `cassetteDirectories` | `[]` | Path pattern to cassette directory, for a project that keeps a module's recordings beside the module: `['tests/Modules/*/' => '{match}/Cassettes']`. Read below `#[CassetteDirectory]` and above `cassetteDirectory`. See [One rule instead of twenty attributes](../integrations/phpunit.md#one-rule-instead-of-twenty-attributes). |
| `testDirectories` | `tests/` under the project root | Where [`tests`](cli.md#tests), [`stale`](cli.md#stale) and [`scan-secrets`](cli.md#scan-secrets) look for test files to parse — every `.php` file under them, since a base test case is rarely named `*Test.php`. Only the CLI uses this. Same root rule as `cassetteDirectory` — nothing looks for `phpunit.xml`. |
| `providers` | `[]` | Named external APIs — host patterns plus the environment variables recording them requires. Optional: `VCR_ERASE_TAPE=@name` also works against bare hostnames without any configuration. See [Providers](../integrations/phpunit.md#providers). |
| `scanRecordingsForSecrets` | `true` | After a session records anything, check the new interactions for credential-shaped values and warn. Never fails a test. See [Redacting Sensitive Data](../safety/redaction.md#the-automatic-check-after-recording). |
| `reportUnplayedInteractions` | `true` | After a session closes, report the recorded interactions it never replayed. Never fails a test. See [Strict & Sequential Mode](../advanced/strict-mode.md#what-happens-without-any-of-it). |
| `persister` | `FilesystemCassettePersister` | [Where cassettes are stored](../advanced/storage-and-formats.md#persisters). |
| `serializer` | `YamlCassetteSerializer` where `symfony/yaml` is installed, `JsonCassetteSerializer` otherwise | [The on-disk format](../advanced/storage-and-formats.md#serializers). Naming one here pins it whatever the project has installed; changing it means running [`migrate`](cli.md#migrate). |
| `defaultMatchers` | `[MethodMatcher, UriMatcher, QueryStringMatcher]` | Used by any `VcrClient` constructed without an explicit `matchers` list. See [why the query string is in there](../concepts/matching.md#the-default-set). |
| `includeSensitiveHeaders` | `[]` | Headers taken back out of the [four redacted with no configuration](../safety/redaction.md#opting-out-of-the-default-header-redaction) — naming all four turns that off. Added to, not replaced by, a client's own `includeSensitiveHeaders()`. |
| `redact` | `[]` | Project-wide [redaction rules](../safety/redaction.md#project-wide-redaction), as `placeholder => value provider`. |
| `innerClientFactory` | detected | Builds the real PSR-18 client `#[UseCassette]` uses when it has to record. See below. |
| PSR-17 factories, `clock`, `scopeResolver`, `strictMode`, `staleAfter`, `inlineBodyLimit` | see [VcrClient Reference](vcr-client.md) | The `VcrClient` constructor parameters that make sense project-wide, as defaults for every instance. |

## `innerClientFactory`

`#[UseCassette]` constructs `VcrClient` on your behalf, so it needs a real client to hand it for the recording path. Resolution mirrors the PSR-17 factories: `innerClientFactory` if configured; otherwise a `class_exists` check against `GuzzleHttp\Client`, `Symfony\Component\HttpClient\Psr18Client`, `Buzz\Client\FileGetContents`; otherwise a [`MissingDependencyException`](exceptions.md).

A replaying test never touches this client, so a missing one only becomes an error at the moment something actually needs to record — and the message says exactly what's missing.

Configure it when the real client needs specific settings (a timeout, a proxy, a client certificate) that matter while recording.

## How `http-vcr.php` is found

The search starts in the process's current working directory and walks upward, stopping at the first `http-vcr.php` it finds — or, if none turns up, at the directory containing `composer.json`. It never goes past that boundary, so a shared CI runner or a monorepo can't accidentally pick up an unrelated config from further up the tree (`$HOME`, for instance).

Not finding a file is not an error. It means the defaults apply.

The same directory — the one holding `composer.json` — is what "project root" means for the default `cassetteDirectory`. One rule covers every entry point: the PHPUnit attribute, a hand-built `VcrClient` in a script, and the CLI.

To bypass discovery for an unusual layout:

```bash
vendor/bin/http-vcr providers --config=path/to/http-vcr.php
```

or configure imperatively, below.

## `VcrClient::configure()`

The same object, filled in from code instead of a file — for a project that would rather configure in a PHPUnit bootstrap than add a config file:

```php
// phpunit.xml <bootstrap> file
VcrClient::configure(
    cassetteDirectory: __DIR__ . '/Cassettes',
    serializer: new YamlCassetteSerializer(),
);
```

These are two entrances to **one** configuration object, not two mechanisms with separate precedence. If both are used, `configure()` overrides field by field what was loaded from `http-vcr.php` — an explicit call in code beats a file picked up automatically in the background, and a field it says nothing about keeps the file's value. `redact` is the exception that proves it: rules from both are things the project asked for, so they add up rather than replace each other.

`--config` is the third way in and the only one that *replaces* rather than merges — the point of naming a file is that the one discovered automatically was the wrong one.

It must be called **once, before the first `VcrClient` is constructed in the process**, and throws `LogicException` afterwards. That's what makes ["no global state"](../concepts/how-it-works.md#no-global-state) true rather than aspirational: by the time any test touches a `VcrClient`, global configuration is already frozen, so there's nothing to reset between tests and no way for execution order to change what a test sees.

## Laravel

The separate [`mtk3d/laravel-http-vcr` package](../integrations/laravel.md) publishes `config/http-vcr.php` with the same options, defaulting `cassetteDirectory` to `base_path('tests/Cassettes')` and `testDirectories` to `base_path('tests')`:

```bash
php artisan vendor:publish --provider="HttpVcr\Laravel\HttpVcrServiceProvider"
```
