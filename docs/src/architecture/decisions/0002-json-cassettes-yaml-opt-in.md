# ADR-0002: JSON cassettes by default, YAML opt-in

**Status:** Accepted · **Reference:** `PLAN.md` §7 decisions 2, 63

## Context

Cassettes are committed to the repository and read in code review, so the on-disk format is
a user interface, not an implementation detail. YAML is what php-vcr uses and what the
ecosystem half-expects. JSON is in the standard library.

## Decision

JSON is the default. YAML is available as `YamlCassetteSerializer` for projects that want
it, and pulls in `symfony/yaml` only if used. Both are thin spellings over
`ArrayCassetteSerializer`, which holds the actual schema.

HAR is deliberately *not* a third serializer — it is an import/export format, handled by
`Import/HarCassetteImporter` and `HarCassetteExporter`.

## Consequences

**Good.** The default install has no serialization dependency. JSON diffs are unambiguous —
no significant whitespace, no block-scalar surprises when a recorded body happens to start
with a dash. Because the schema lives in one place, adding a field means touching
`ArrayCassetteSerializer` once and both formats gain it.

**Bad.** JSON has no comments, so a hand-annotated cassette is not possible; and long
single-line bodies are less pleasant to read than a YAML block scalar. Projects that care
can switch, at the cost of one dependency.

**Note on schema evolution.** Cassettes carry `schemaVersion` from the very first release,
before there was anything to version. Adding it later would have meant guessing the shape
of files already committed in other people's repositories.
