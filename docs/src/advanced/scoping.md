# Scoping Cassettes by URL

Some APIs version themselves in the URL — Shopify puts a date in the path (`/admin/api/2024-01/...`), others use `/v2/`, `/v3/`. When application code moves from one version to the next, you want a clear, readable error — "no cassette recorded for this version" — not a generic "none of these interactions match," and definitely not a silent match against the wrong, outdated interaction.

The default [`UriMatcher`](../concepts/matching.md) already partly handles this — a different URL segment simply won't match — but that mixes the old and new version into one file and doesn't say *why* nothing matched. Scoping resolves this explicitly, at the level of which cassette **file** gets used, not just at the matching level.

```php
interface CassetteScopeResolverInterface {
    public function resolve(RequestInterface $request): ?string;
}
```

## Built-in resolvers

- **`NullScopeResolver`** (default) — no scoping, unchanged behavior.
- **`RegexUrlScopeResolver`** — extracts a scope from the URL via a named capture group:

  ```php
  new RegexUrlScopeResolver('#/api/(?<scope>\d{4}-\d{2})/#')  // Shopify: date
  new RegexUrlScopeResolver('#/v(?<scope>\d+)/#')             // version number
  ```

- **`CallbackScopeResolver`** — arbitrary logic, e.g. reading the version from a header instead of the URL (`Accept: application/vnd.api+json;version=3`).

A resolver applies per request, not per cassette: a URI the pattern doesn't match is unscoped and belongs in the cassette's own file. That's what makes a resolver safe on a cassette that also carries traffic the versioning doesn't apply to — an OAuth token endpoint outside the versioned path, say.

## What happens on a version bump

A cassette named `ProductsTest__getProduct` at `scope = 2024-01` is stored as `ProductsTest__getProduct.2024-01.yaml`. Once the application starts calling `2024-04`, `VcrClient` computes the new scope and doesn't find a file for it. What happens then depends on *why* it couldn't just record one — the exception names the actual cause, following the same rule as [everywhere else](../reference/exceptions.md#which-one-you-get-when-nothing-came-back):

**`PlaybackOnly`** — the declared mode rules recording out, so `CassetteNotFoundException`. No environment variable would change that, and the message doesn't pretend otherwise:

```
No cassette recorded for scope "2024-04" (base: ProductsTest__getProduct).
Existing scopes: 2024-01. Mode is PlaybackOnly, which never records —
record it under RecordIfAbsent, or add the missing scope by hand.
```

**`RecordIfAbsent` / `ExtendCassette` with recording blocked** — `RecordingNotAllowedException`, because that's the real reason: the identical run with recording allowed would have recorded the new scope and passed.

```
Cannot record cassette "ProductsTest__getProduct" (scope "2024-04"):
recording is disabled by CI detection (CI=true is set, VCR_ALLOW_RECORDING
is not). Existing scopes: 2024-01.
```

**`RecordIfAbsent` / `ExtendCassette` with recording allowed** — no exception at all: a new file is recorded for the new scope, exactly like the first recording of any cassette.

The `Existing scopes:` line is common to both failures — it's the part that actually carries the information here ("`2024-01` is on disk, the code is asking for `2024-04`"), whichever exception ends up being thrown.

## Scopes become filenames

A scope is appended to the cassette filename, and `CallbackScopeResolver` can return anything at all — a header value, whatever a closure computes. So a scope is sanitized before it's used as a path component: characters outside `[A-Za-z0-9_.-]` become `_`, and a result that's empty, `.`, `..`, or starts with a dot is rejected outright rather than turned into a hidden file or a path escape. A scope is always a single path segment; a `/` inside it is just another character to replace, not a directory separator.

The cassette *name* is different — there, `/` is meaningful (`shopify/get-product` lives in a `shopify/` subdirectory), so each segment is sanitized on its own and the resolved path is checked to still be inside the cassette directory.

## Two independent axes

This is a second, independent axis from [`staleAfter`](stale-after.md): `staleAfter` is about elapsed time, scoping is about a contract change visible in the URL itself. Both can be active together — scope decides *which* file matters, `staleAfter` makes sure that file doesn't go stale even if the version never changes.
