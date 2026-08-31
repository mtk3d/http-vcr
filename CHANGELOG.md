# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version is `0`, a minor bump may carry a breaking change; the entry says
so when it does.

## [Unreleased]

### Changed

- **Guzzle 8 is supported and tested.** The `Bridge/Guzzle/VcrMiddleware` handler-stack
  bridge needed no change — `guzzlehttp/promises` 3.0 kept the signatures it uses — but the
  development dependency pinned `^7.0`, so the bridge had never been run against Guzzle 8.
  It is now `^7.0 || ^8.0`: the main CI legs resolve to Guzzle 8, and the lowest-dependencies
  leg keeps Guzzle 7 covered. Nothing in `require` changed, because the library never
  required Guzzle in the first place.

## [0.1.1] - 2026-08-31

Documentation and package metadata only; nothing in `src/` changed. The Laravel material
shipped in 0.1.0 was written before the bridge existed, and building it showed three of its
claims to be wrong.

### Fixed

- **The Laravel recipe did not work.** `examples/laravel-http-recipe.php` and the Laravel
  page installed the middleware with `Http::globalOptions(['handler' => ...])`.
  `PendingRequest::createClient()` hands its own stack to the Guzzle client as a constructor
  option, and `handler` is not a per-request option, so the handler was never consulted — a
  base test case copied from that recipe opened no cassettes and let every call reach the
  real API, looking exactly like one that had. The hook is `Http::globalMiddleware()`, which
  takes raw handler-stack middleware and appends to whatever the application registered.
- **The configuration reference promised a `vendor:publish` that does not exist.** No
  `config/http-vcr.php` is published by anything. A Laravel application uses the same
  root-level `http-vcr.php` as any other project, and for the common case declares nothing:
  the defaults already resolve to `base_path('tests/Cassettes')` and `base_path('tests')`.
- **The documented Laravel range is 12-13, not 11-12.** Every 11.x release is covered by
  advisory `PKSA-mdq4-51ck-6kdq` with no patched release, so Composer declines to install
  that branch at all.

### Changed

- **`mtk3d/laravel-http-vcr` is on Packagist**, so this package now `suggest`s it and the
  documentation points at it directly rather than sending every Laravel user to the manual
  recipe. The recipe stays documented as the way to cover Laravel without the extra package.

## [0.1.0] - 2026-08-30

First release.

### Added

- **Record and replay over PSR-18.** `VcrClient` decorates any
  `Psr\Http\Client\ClientInterface`: the first run performs the real request and writes it
  to a cassette, every run after replays from the file. Two instances in one process do not
  interfere with each other.
- **Record modes.** `RecordIfAbsent` (the default), `PlaybackOnly` and `ExtendCassette`,
  plus `VCR_ERASE_TAPE` to force a re-recording of one cassette, one provider's traffic, or
  everything a run opens.
- **Recording is refused on CI.** Detected from `CI`, `CONTINUOUS_INTEGRATION`,
  `BUILD_NUMBER`, `JENKINS_URL` and `TEAMCITY_VERSION`, and overridable with
  `VCR_ALLOW_RECORDING`, so a missing cassette fails loudly instead of quietly reaching a
  real API.
- **Matchers.** `Method`, `Uri`, `Host`, `QueryString`, `Headers`, `Body` and `BodyJson`,
  composable and configurable — `ignoreJsonField()`, `matchJsonField()`,
  `ignoreQueryParam()`, `matchOnlyQueryParams()`. A failed match reports which matcher
  rejected which interaction and why.
- **Redaction and secret scanning.** `Authorization`, `Cookie`, `Set-Cookie` and
  `Proxy-Authorization` are redacted with no configuration; `redact()`, `redactHeader()`,
  `redactQueryParam()`, `redactFormField()` and `redactResponseField()` name the rest, at
  the client or project-wide. A recording session warns about credential-shaped values it
  stored in the clear.
- **`beforeRecord` / `beforePlayback` hooks**, the pipeline redaction itself is built on.
- **Strict modes.** `AllPlayed` and `InOrder`; by default a cassette holding interactions
  nothing asked for is reported rather than failed.
- **`staleAfter`**, with named intervals (`Stale::Week`, `Stale::Month`, …) beside
  `DateInterval`, and `VCR_ENFORCE_STALE_CHECK` to make an expired recording fail a run.
- **Cassette scoping by URL**, so one declared cassette can hold one file per tenant,
  environment or account.
- **Edge cases handled as recorded behaviour**, not as failures: non-seekable streams,
  binary bodies, bodies past the inline limit moved to sidecar files, response
  decompression, and transport failures recorded and replayed as failures.
- **Bridges.** `Bridge/Guzzle/VcrMiddleware` for a `HandlerStack`,
  `Bridge/Symfony/VcrHttpClient` for Symfony's native `HttpClientInterface`, and a PHPUnit
  bridge — `#[UseCassette]`, `#[CassetteDirectory]`, `InteractsWithCassettes`, `Extension`,
  `CurrentCassetteSession`.
- **Providers.** A name for an external API — host patterns plus the environment variables
  recording it requires, checked per request before anything is recorded.
- **Configuration** from `http-vcr.php` found by walking up from the working directory, or
  from code via `VcrClient::configure()`. `cassetteDirectories` routes cassettes by where
  the test file lives, for projects split into modules.
- **Storage.** JSON and YAML cassettes on a shared schema, with YAML the default wherever
  `symfony/yaml` is installed; filesystem storage with session locking; HAR import and
  export.
- **CLI** (`vendor/bin/http-vcr`): `stale`, `tests`, `providers`, `scan-secrets` (with
  `--redact`), `lock`, `unlock` and `migrate`.

### Notes

- The record/replay core depends on `psr/http-message`, `psr/http-client`,
  `psr/http-factory` and `psr/clock`, and nothing else. `symfony/console` and
  `nikic/php-parser` are required for the CLI and are not reachable from the request path.
- PHP 8.2 through 8.5. The PHPUnit bridge supports PHPUnit 10 through 13.
- The Laravel bridge (`mtk3d/laravel-http-vcr`) is not released yet; the manual recipe in
  the documentation covers Laravel in the meantime.

[Unreleased]: https://github.com/mtk3d/http-vcr/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/mtk3d/http-vcr/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mtk3d/http-vcr/releases/tag/v0.1.0
