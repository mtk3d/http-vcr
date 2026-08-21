# http-vcr

Record and replay HTTP interactions for fast, deterministic PHP tests — built as a decorator over PSR-18 (`Psr\Http\Client\ClientInterface`) instead of monkey-patching curl/streams.

Status: **in progress** — phases M1 through M3 of [PLAN.md § 6](./PLAN.md#6-fazy-budowy) are built:

- **Core record/replay** — `VcrClient` as a PSR-18 decorator, the three `RecordMode` cases, the full matcher set (`Method`, `Uri`, `Host`, `QueryString`, `Headers`, `Body`, `BodyJson`), the JSON cassette format, filesystem storage with session locking, `VCR_ALLOW_RECORDING`/`VCR_ERASE_TAPE`, binary and oversized bodies, response decompression, recorded transport failures.
- **Redaction and secret scanning** — the `beforeRecord`/`beforePlayback` hook pipeline, bidirectional `redact()` and its four helpers, and the heuristic scan that warns about credentials in freshly recorded interactions.
- **Advanced modes** — `StrictMode::AllPlayed`/`InOrder`, `staleAfter` with `VCR_ENFORCE_STALE_CHECK`, cassette scoping by URL, and `vendor/bin/http-vcr lock`/`unlock`.

Not built yet: the Guzzle, Symfony and PHPUnit bridges (M4), and the `stale`, `tests`, `providers` and `scan-secrets` commands, the YAML serializer and HAR import/export (M5).

See [PLAN.md](./PLAN.md) for architecture, scope, and build phases, and [`docs/`](./docs/src/SUMMARY.md) for the user-facing documentation (mdBook: `mdbook build docs`), which describes the library as planned in full.

## Why not php-vcr / php-http/vcr-plugin?

See [PLAN.md § 1](./PLAN.md#1-pozycjonowanie) for the full comparison. Short version: php-vcr hooks curl/streams globally (fragile across Guzzle/curl versions), and `php-http/vcr-plugin` requires the httplug `PluginClient` stack. http-vcr targets any PSR-18 client directly, adds bidirectional redaction (request *and* response, without breaking replay matching), semantic JSON body matching, and an opt-in strict/sequential replay mode.

The record/replay core depends on nothing but `psr/http-message`, `psr/http-client`, `psr/http-factory`, and `psr/clock`. The package additionally requires `symfony/console` and `nikic/php-parser` for its CLI; since http-vcr is installed as a dev dependency, neither reaches an application's production autoloader.

## License

MIT — see [LICENSE](./LICENSE).
