# Working conventions — http-vcr

Record and replay HTTP interactions in tests, as a decorator over a PSR-18 client.

**State: M1 through M5 built** — `VcrClient` records and replays through the PSR-18
decorator, with the full matcher set (`Method`, `Uri`, `Host`, `QueryString`, `Headers`,
`Body`, `BodyJson`), the JSON cassette format, filesystem storage with session locking,
CI-aware recording permission, `VCR_ERASE_TAPE`, the edge cases M1.5 covers (non-seekable
streams, binary bodies, sidecar files, response decompression, recorded transport
failures), M2's two differentiators (the `beforeRecord`/`beforePlayback` hook pipeline with
redaction built on it, and the secret scan that warns after a recording session), M3's
advanced modes (`RecordMode::ExtendCassette`, `StrictMode::AllPlayed`/`InOrder`,
`staleAfter` with `VCR_ENFORCE_STALE_CHECK`, cassette scoping by URL), M4's adapters
(`Bridge/Guzzle/VcrMiddleware` on `VcrClient::withInner()`, `Bridge/Symfony/VcrHttpClient`,
the PHPUnit bridge — `#[UseCassette]`, `#[CassetteDirectory]`, `InteractsWithCassettes`,
`Extension`, `CurrentCassetteSession` — named providers resolved by host, `requiresEnv`
checked per request, and `http-vcr.php` with `--config`), and M5's tooling: the seven CLI
commands (`stale`, `tests`, `providers`, `scan-secrets`, `lock`, `unlock`, `migrate`) on the
`TestScanner` AST scan of `#[UseCassette]`, `YamlCassetteSerializer` — the default wherever
`symfony/yaml` is installed (§7 decisions 2, 65) — beside the JSON one on
a shared `ArrayCassetteSerializer`, `Import/HarCassetteImporter`/`HarCassetteExporter`, the
examples in `examples/`, and the book built and published from CI.

Every phase of the plan is built. New work is either a §8 idea being promoted (write the
decision in §7 first) or a correction to something already here.

The repository's own `http-vcr.php` pins the cassette format to JSON: `symfony/yaml` is a
dev dependency here, so the default would resolve to YAML, while the committed fixtures are
JSON and many tests read the file that landed on disk. It is this repository's
configuration, not an example — a consuming project usually declares nothing.

`phpunit.xml.dist` also sets `NO_COLOR=1`: warnings printed to standard error are colored
(§7 decision 66), and without that pin a suite asserting their text verbatim would pass in
a pipeline and fail in a terminal. A test that wants the colored form asks for it with
`Ansi::assume(true)` and puts detection back with `Ansi::assume(null)`.

The library's own `phpunit.xml.dist` registers `HttpVcr\Bridge\PHPUnit\Extension`, and
`tests/Integration/PHPUnitBridgeTest.php` runs through it against committed cassettes in
`tests/Integration/Cassettes/` — that is the M4 bridge testing itself, not a fixture to
tidy away.

Build order was `PLAN.md` §6 — M1 → M1.5 → M2 → M3 → M4 → M5 — a dependency order rather
than a wish list. Decisions taken while implementing a phase go to `PLAN.md` §7 like any
other (18–25 came out of M1, 26–31 out of M1.5, 33–40 out of M2, 41–45 out of M3, 46–55 out
of M4, 57–64 out of M5).

## Language

- `PLAN.md` and design discussion: **Polish**.
- `docs/`, code, comments, commit messages, exception messages: **English**.

## Where things live

- **Design decisions** → `PLAN.md` §7, each with the rejected alternatives and why.
- **Reasoning behind a decision** → `PLAN.md`, never `docs/`.
- **Working instructions** → this file.
- `docs/` describes what the library does, for someone using it.

Changing a decision in code without updating `PLAN.md` §7 is unfinished work. If a
verified fact turns out wrong, record the correction in place — do not quietly drop it.

## Constraints that are easy to violate

- **Target PHP 8.2 syntax.** The local interpreter is newer and CI runs 8.2–8.5, so
  anything 8.3+ (property hooks, asymmetric visibility, `new` in more places) compiles
  locally and breaks the lowest leg.
- **Only symfony/console APIs present across `^6.4 || ^7.0 || ^8.0` may be used.** The
  three majors disagree, and the local interpreter can't even install Symfony 8 (it
  requires PHP 8.4+), so a wrong call compiles and passes locally and breaks the two top
  CI legs — the same trap as the PHP version above. The one already hit: `Application::add()`
  was removed in 8.0 and `addCommand()` only arrived in 7.4, so registering a command goes
  through `addCommands()`, which all three have. Check a method against the actual 8.x
  source before using it (`composer require symfony/console:^8.0 --ignore-platform-req=php`
  in a scratch directory).
- **The record/replay core depends on `psr/http-message`, `psr/http-client`,
  `psr/http-factory` and `psr/clock`, and nothing else.** That promise is half the reason the library
  exists (§1). `symfony/console` and `nikic/php-parser` are in `require` for the CLI
  only and must not be reachable from the request path. Any new dependency needs a
  decision in §7 first.
- **Class, method and option names are http-vcr's own** and deliberately unlike Ruby
  VCR, php-vcr or go-vcr even where the semantics match (§1). Don't "correct"
  `RecordMode::RecordIfAbsent` to someone else's vocabulary.
- **The Laravel bridge lives in a separate repository** (`mtk3d/laravel-http-vcr`,
  §3.13). Nothing Laravel-specific belongs in `src/`, and `composer.json` carries no
  `extra.laravel` entry.

## Public surface

`HttpVcr\Bridge\PHPUnit\CurrentCassetteSession` is a **BC-guaranteed public contract**,
not an internal detail: the separate Laravel package reads it at request time (§3.13).
Refactoring `Bridge/PHPUnit/` must preserve it.

## Commits

- One commit = one coherent behavior change, test suite green.
- Reference the decision being implemented: `matching: add QueryStringMatcher to defaults (§7 decision 15)`.
- Scaffolding with nothing working yet may be a single commit; nothing else may.
- Never generate the history in bulk at the end — a message must carry intent, not restate the diff.

## Before each commit

```bash
composer install                    # no vendor/ or lock file exists yet
vendor/bin/phpunit
vendor/bin/phpstan analyse          # level max, from the first commit
vendor/bin/pint --test              # Laravel Pint, laravel preset
```

These three run in CI from the first commit, not from M5. The config files they need
(`phpunit.xml.dist`, `phpstan.neon.dist`, `pint.json`) are part of M1
scaffolding — see `PLAN.md` §5 for the intended layout.

After touching `docs/`, rebuild the book (`mdbook build docs`) — `SUMMARY.md`
drifting from the files on disk only surfaces at build time.

The book needs `mdbook-mermaid` for the C4 diagrams under `docs/src/architecture/`.
Run `mdbook-mermaid install docs` once; it writes `docs/mermaid.min.js` and
`docs/mermaid-init.js`, which are gitignored rather than carried in the repository
because the first is 2.5 MB. `book.toml` already references them, so a build
without that step fails on the missing asset. CI does the same install.

Mermaid renders in the browser, so a syntax error in a diagram does not fail the
build — it fails silently on the published page. Diagrams are worth parsing
before pushing.

`docs/seo.sh` runs after that build in CI, stamping canonical links and writing
the sitemap. It reads the first chapter out of `SUMMARY.md`, so reordering the
book is enough to change which page the site root canonicalises to.

Write the test for the behavior before implementing it. A passing test is the only
hard evidence that generated code does what it looked like it did.

## Writing `docs/`

Three rules, in order of precedence. The reasoning behind them is in `PLAN.md` §6, M5.

1. **Justify only what the reader can act on.** The test is not "might they wonder"
   but "will they do something differently knowing this". If a paragraph can be
   deleted without changing anything the reader does, it goes.
2. **No plan archaeology.** "An earlier version assumed…", arguing with a rejected
   alternative the reader never saw. That belongs in `PLAN.md`.
3. **Report state, don't prescribe repair.** No "Fix with…" where nothing is broken,
   no promises about how helpful the error messages are. Show the message or give
   the command; skip the commentary. Remedial wording is allowed only when something
   genuinely is broken and one action unblocks it.

## Verifying claims

Version constraints, framework APIs and library internals get checked against the
source or Packagist before they enter the plan — not asserted from memory. Two of
eight such facts were wrong when checked.
