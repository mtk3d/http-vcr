# ADR-0006: Configuration freezes at the cassette session

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 17

## Context

`VcrClient` accepts configuration after construction — `redact()`, `beforeRecord()`,
`includeSensitiveHeaders()`. Anything registered *after* traffic has already flowed would
apply to some interactions and not others, producing a cassette where half the entries were
redacted and half were not. That is worse than either extreme, because it looks fine.

The question is where to draw the line. Per `VcrClient` instance is the obvious answer and
the wrong one: the Guzzle bridge produces a new `VcrClient` per request
([ADR-0011](0011-guzzle-integrates-through-with-inner.md)), so an instance boundary would
freeze after every single request.

## Decision

The boundary is the **cassette session**, not the object. Calling a configuring method after
the session's first request throws `LogicException`, naming the method and explaining that
an interaction has already been through the pipeline it configures.

Project-wide `Config` freezes on first use too, via `Config::freeze()` in the `VcrClient`
constructor.

## Consequences

**Good.** The rule matches what a reader would assume: everything in the cassette went
through the same pipeline. It survives the satellite-instance pattern the bridges rely on,
because satellites share the session. The error is loud, immediate, and says what to do.

**Bad.** Configuration must happen before the first request, which is a real constraint for
code that discovers a redaction rule mid-test. The escape hatch is the config file:
`http-vcr.php` rules are project-wide and always in place before anything starts.
