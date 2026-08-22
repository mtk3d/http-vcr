# http-vcr

Record and replay HTTP interactions for fast, deterministic PHP tests — built as a decorator over PSR-18 (`Psr\Http\Client\ClientInterface`) instead of monkey-patching curl/streams.

Status: **in progress** — phases M1 through M4 of [PLAN.md § 6](./PLAN.md#6-fazy-budowy) are built:

- **Core record/replay** — `VcrClient` as a PSR-18 decorator, the three `RecordMode` cases, the full matcher set (`Method`, `Uri`, `Host`, `QueryString`, `Headers`, `Body`, `BodyJson`), the JSON cassette format, filesystem storage with session locking, `VCR_ALLOW_RECORDING`/`VCR_ERASE_TAPE`, binary and oversized bodies, response decompression, recorded transport failures.
- **Redaction and secret scanning** — the `beforeRecord`/`beforePlayback` hook pipeline, bidirectional `redact()` and its four helpers, and the heuristic scan that warns about credentials in freshly recorded interactions.
- **Advanced modes** — `StrictMode::AllPlayed`/`InOrder`, `staleAfter` with `VCR_ENFORCE_STALE_CHECK`, cassette scoping by URL, and `vendor/bin/http-vcr lock`/`unlock`.
- **Adapters** — a Guzzle `HandlerStack` middleware (so calls through Guzzle's own API are covered, not just `sendRequest()`), a bridge for Symfony's native `HttpClientInterface`, and the PHPUnit integration: `#[UseCassette]`, `#[CassetteDirectory]`, `InteractsWithCassettes`, the `Extension` registered in `phpunit.xml`.
- **Project configuration** — an optional `http-vcr.php`, named providers recognised by host (`VCR_ERASE_TAPE=@shopify`), and `requiresEnv` pre-validated per request before anything is recorded.

Not built yet (M5): the `stale`, `tests`, `providers` and `scan-secrets` commands, the YAML serializer, and HAR import/export.

See [PLAN.md](./PLAN.md) for architecture, scope, and build phases, and [`docs/`](./docs/src/SUMMARY.md) for the user-facing documentation (mdBook: `mdbook build docs`), which describes the library as planned in full.

## Dependencies

The record/replay core depends on nothing but `psr/http-message`, `psr/http-client`, `psr/http-factory`, and `psr/clock`. The package additionally requires `symfony/console` and `nikic/php-parser` for its CLI; since http-vcr is installed as a dev dependency, neither reaches an application's production autoloader.

## License

MIT — see [LICENSE](./LICENSE).
