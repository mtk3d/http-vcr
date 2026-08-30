# Roadmap

Ideas worth keeping track of, but not scheduled into any milestone yet:

- **Match diagnostics on demand** (`VCR_DEBUG_MATCHING=1`) — a full match trace for every interaction × matcher, not just when nothing matches at all.
- **`diff`** — a semantic diff between two versions of the same cassette (JSON Pointer level, not line-by-line), for reviewing what an updated recording actually changed.
- **A repair run: replay, then re-record only what failed on its cassette** — one command that runs the suite in playback, collects the tests that failed *on a cassette* (no match, no file, gone stale — not on an assertion), and re-runs just those with recording forced for their cassettes. Today that loop is manual: read which failed, assemble a `--filter`, add `VCR_ERASE_TAPE`. Different from `update` in where the scope comes from — `update` is handed a cassette name, this reads it off a run.
- **`update`** — re-record a cassette, show a semantic diff of what changed, and ask for confirmation instead of blindly overwriting.
- **Schema drift detection** — flag when a re-recorded interaction's JSON shape changed (fields added or removed), independent of `staleAfter`, which only measures elapsed time.
- **Subset JSON matching** — alongside the current strict `BodyJsonMatcher`, a mode where the recorded body only needs to be a subset of the incoming one, or vice versa.
- **`MultipartMatcher`** — structural matching for `multipart/form-data`, since a random boundary makes raw body matching useless.
- **`CookieMatcher` / `SetCookieMatcher`** — structural parsing of `Cookie`/`Set-Cookie` instead of treating them as opaque header strings.
- **Pattern-based header matching** — a header equivalent of `matchJsonField`, for headers like `Idempotency-Key` or `traceparent` that shouldn't be matched exactly or ignored entirely.
- **Ordered query string matching** — `QueryStringMatcher` compares parameters as an unordered set and can be told which ones count (`ignoreQueryParam()`, `matchOnlyQueryParams()` — both built); what's left is choosing order-sensitive comparison for an API where `?a=1&b=2` and `?b=2&a=1` are not the same request.
- **Request assertions (`expectRequest()`)** — a cassette that's both a fixture and a verification that the code under test actually sent the expected data, not just that it got a response back.
- **Deduplication via repeat count** — one entry plus `repeat: N` instead of N identical interactions for N identical calls.
- **Parallel recording to separate files, merged after the fact** — an alternative to the session-wide lock, for teams that deliberately want to record in parallel.
- **Provider-scoped redaction** — redaction rules attached to a `Provider` (applied to interactions whose host matches) instead of only the flat, project-wide `redact` in `Config`. Coherent since providers became a core concept, but deliberately deferred: the common case is already covered by the default `Authorization` redaction, and the rest has a one-line workaround in a base test case.
- **File-level cassette metadata** (`generator`, `source.test`, `source.provider`) — to support tooling like `list`/`info` without parsing every interaction.

None of this is committed to a milestone — it's tracked here so it isn't lost, not because it's coming soon.
