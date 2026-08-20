# Working conventions — http-vcr

Record and replay HTTP interactions in tests, as a decorator over a PSR-18 client.

**State: planning finished, no implementation yet.** `src/`, `tests/`, `bin/` and the
tooling config files are empty or absent by design. Build order is `PLAN.md` §6 —
M1 → M1.5 → M2 → M3 → M4 → M5 — and it is a dependency order, not a wish list: don't
implement a later phase's feature while an earlier one is unbuilt.

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
- **The record/replay core depends on `psr/http-message`, `psr/http-client` and
  `psr/http-factory`, and nothing else.** That promise is half the reason the library
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
vendor/bin/php-cs-fixer fix --dry-run
```

These three run in CI from the first commit, not from M5. The config files they need
(`phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php`) are part of M1
scaffolding — see `PLAN.md` §5 for the intended layout.

After touching `docs/`, rebuild the book (`mdbook build docs`) — `SUMMARY.md`
drifting from the files on disk only surfaces at build time.

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
