# ADR-0017: YAML cassettes wherever `symfony/yaml` is installed

**Status:** Accepted · **Supersedes:** [ADR-0002](0002-json-cassettes-yaml-opt-in.md) · **Reference:** `PLAN.md` §7 decisions 2, 63, 65

## Context

[ADR-0002](0002-json-cassettes-yaml-opt-in.md) made JSON the default because YAML costs a
dependency and the record/replay path may depend on nothing but the PSR packages.

Using the library made the other half of the trade-off concrete. A cassette is committed and
read in review, and the thing most often read in it is a response body. JSON has to escape
every newline in one, so an HTML or XML response arrives in a diff as a single line
thousands of characters wide. YAML writes the same body as a literal block. The format that
is nicer to read is the one nobody selects, because selecting it means knowing it exists.

## Decision

The default format follows the project rather than the library: `YamlCassetteSerializer`
where `symfony/yaml` is installed, `JsonCassetteSerializer` where it isn't. A serializer
named in `http-vcr.php` still wins over both.

`symfony/yaml` stays out of `require`. The dependency promise in §1 is about what the
record/replay path *needs*, and a default that adapts to what is present keeps that intact,
where a hard dependency would not.

## Consequences

**Good.** The readable format is what a project with Symfony components already installed —
which is most Laravel and Symfony projects — gets without choosing it. Nothing is added to
anyone's dependency tree.

**Bad.** The format now depends on the contents of `vendor/`, so two projects on the same
version of http-vcr can record differently, and a project can change format by installing an
unrelated package. Pinning the serializer in `http-vcr.php` is the answer where that
matters, and it is one line.

**Worse, and the reason `migrate` exists.** A cassette is only ever looked for under the
extension its serializer owns, so the day the default flips, existing `.json` cassettes stop
being found: `RecordIfAbsent` re-records the lot against the real API, `PlaybackOnly` raises
`CassetteNotFoundException` for every one. `vendor/bin/http-vcr migrate --to=yaml` rewrites
them in place, and the [CLI reference](../../reference/cli.md#migrate) says so where the
format is documented. A silent format detection that read both extensions would have avoided
the migration entirely — at the cost of a project permanently able to hold two formats at
once with nothing pointing it out.
