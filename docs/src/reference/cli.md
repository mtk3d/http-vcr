# CLI Reference

```bash
vendor/bin/http-vcr <command> [options]
```

In a Laravel app with the separate [`mtk3d/laravel-http-vcr` package](../integrations/laravel.md), the same six commands are also available as Artisan commands, prefixed with `vcr:` (`stale` → `vcr:stale`, `tests` → `vcr:tests`, and so on) — Artisan commands share one flat namespace across the whole framework and every installed package, so they need a prefix to stay collision-free and easy to find in `artisan list`; a standalone single-purpose binary like `vendor/bin/http-vcr` doesn't have that problem, so its commands stay bare.

## `stale`

```bash
vendor/bin/http-vcr stale
```

Lists interactions that have crossed [`staleAfter`](../advanced/stale-after.md) — meant as a separate, non-blocking CI step, not a build gate. The per-cassette threshold is read from `#[UseCassette(staleAfter: ...)]` via the same AST scan `tests` uses (not by running any tests) — a cassette no test declares a threshold for is simply skipped, and conflicting thresholds declared for the same cassette name are reported rather than silently resolved.

With [scoped cassettes](../advanced/scoping.md), one attribute names a base cassette that exists on disk as several scope files. The declared threshold applies to all of them, and the report names the specific scope file, not just the base name.

## `providers`

```bash
vendor/bin/http-vcr providers
```

Prints each [provider](../integrations/phpunit.md#providers) configured in `http-vcr.php` — its host patterns, its `requiresEnv`, and how many cassettes and interactions belong to it — then the hosts running on implicit providers, i.e. those no configuration has claimed:

```
shopify        *.myshopify.com          SHOPIFY_API_KEY     4 cassettes, 11 interactions
zendesk-a      account-a.zendesk.com    ZENDESK_A_API_KEY   2 cassettes, 3 interactions

Implicit (addressable by host, no requiresEnv):
  api.stripe.com          2 cassettes, 5 interactions
```

A host on an implicit provider works fine — `VCR_ERASE_TAPE=@api.stripe.com` targets it like any other — it just has no `requiresEnv` and no shorter name. This section is the shortlist of integrations worth declaring in `http-vcr.php`.

## `tests`

```bash
vendor/bin/http-vcr tests --provider=shopify
vendor/bin/http-vcr tests --provider=shopify --filter-only
```

Lists the tests that touch a given provider, plus a ready-made regex for PHPUnit's `--filter`. `--filter-only` prints just the regex, for dropping into a shell substitution:

```bash
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify \
  vendor/bin/phpunit --filter "$(vendor/bin/http-vcr tests --provider=shopify --filter-only)"
```

This is a speed optimization, never a safety requirement — what gets erased and re-recorded is decided by the `VCR_ERASE_TAPE` selector, so the same command without any filter produces the same cassettes, just slower.

It answers the question by combining two sources, neither sufficient alone: an AST scan of `#[UseCassette]` (which test opens which cassette) and the **contents of the cassettes** (which hosts actually appear in them). The second is what makes it work for a test whose cassette name gives away nothing about it talking to Shopify.

One limitation that falls straight out of that: **a test whose cassette doesn't exist yet won't be listed**, because there's nothing to scan. Record it the first time with an unfiltered run.

Loading the test classes to read their attributes by reflection would mean every one of them needing a correctly configured environment — the exact thing the CLI is supposed to avoid — so it parses the syntax instead. What the scan can resolve statically: scalar literals and arrays of them, enum cases (`RecordMode::RecordIfAbsent`), and `new DateInterval('P7D')`, which is what `staleAfter` almost always looks like since PHP 8.1 allows `new` in attribute arguments. Anything else (`staleAfter: self::INTERVAL`, a computed cassette name) is reported as "couldn't be fully analyzed" rather than guessed at.

## `scan-secrets`

```bash
vendor/bin/http-vcr scan-secrets
```

The full, manual pass of the same scanner that [runs automatically after every recording](../safety/redaction.md#the-automatic-check-after-recording) — a heuristic sweep of every cassette's contents for `Bearer ` tokens, AWS-style `AKIA[0-9A-Z]{16}` keys, long hex/base64 strings in fields that look like tokens, and `Authorization`/`Cookie`/`Set-Cookie` values that don't look like placeholders.

Two things this adds over the automatic check: it covers **every** cassette, not just what a run happened to record, and it has an exit code, so CI can make it blocking. Non-blocking by default.

The test is what the value *looks like*, not which `redact()` rules exist: this command doesn't run the test suite, so it can't know about rules registered imperatively in a `setUp()`. Anything in the `<...>` convention counts as a placeholder — both the built-in `<REDACTED-*>` values and your own `<API_KEY>`. Rules declared in `http-vcr.php` are read too, since that file can be loaded without running anything.

[Sidecar files](cassette-format.md#sidecar-files) are scanned as well. They hold the largest payloads in the project and go through redaction like any inline body, so leaving them out would be the exact gap this command exists to close. Sidecars whose content isn't text (an image, an archive) are skipped, to avoid false positives from arbitrary byte sequences.

## `lock` / `unlock`

```bash
vendor/bin/http-vcr lock shopify/checkout --interaction=2
vendor/bin/http-vcr unlock shopify/checkout --interaction=2
```

Sets or clears `"locked": true` on a specific interaction. Without `--interaction`, applies to every interaction in the file at once. With [scoped cassettes](../advanced/scoping.md), a bare name covers every scope file for that cassette; `--scope=2024-01` narrows it to one. See [Locked Interactions](../safety/locked-interactions.md).

## Running from inside a consuming project

Composer links the binary into the consuming project's `vendor/bin`, and the script resolves the *host* project's `vendor/autoload.php` and `http-vcr.php`, not its own package directory — so it works the same whether it's run from a project that depends on http-vcr or from http-vcr's own test suite. For an unusual layout, point it at a config explicitly:

```bash
vendor/bin/http-vcr providers --config=path/to/http-vcr.php
```

The CLI is built on `symfony/console` and reads attributes with `nikic/php-parser`; both are regular dependencies of the package rather than optional extras, so these commands work immediately after `composer require --dev mtk3d/http-vcr`. Neither reaches a production autoloader, since the package itself is a dev dependency. The record/replay core doesn't touch either one.
