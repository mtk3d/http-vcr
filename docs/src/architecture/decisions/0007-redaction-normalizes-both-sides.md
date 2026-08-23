# ADR-0007: Redaction normalises both sides instead of special-casing matchers

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 9

## Context

Redaction replaces a secret with a placeholder before the interaction reaches disk. The
cassette then holds `Authorization: Bearer <REDACTED>`. On the next run the live request
still carries the real token — so a header matcher comparing the two sees
`Bearer sk_live_abc…` against `Bearer <REDACTED>` and reports a mismatch. The cassette is
correct, the request is correct, and matching fails anyway.

Two ways out. Teach the matchers about redaction — every matcher gains a special case for
placeholder values. Or make sure both sides look the same by the time matching happens.

## Decision

Both sides are normalised. The incoming request goes through the same redaction rules
before it is compared, so a redacted field is compared placeholder-to-placeholder. Matchers
know nothing about redaction at all.

## Consequences

**Good.** `RequestMatcherInterface` stays a pure comparison of two `RecordedRequest`s, which
is what makes it plausible to implement from outside. A custom matcher inherits correct
redaction behaviour without knowing redaction exists. Adding a new redaction target does not
mean revisiting every matcher.

**Bad.** A redacted field stops distinguishing requests. If two requests differ *only* in a
redacted header, they now look identical and the first recorded interaction answers both.
This is real and occasionally surprising — `includeSensitiveHeaders()` exists precisely for
it, storing the header as sent so it can tell requests apart again.

**Two-way rules.** A rule with a value callback restores the real value on playback, so the
code under test receives what it expects. A field is only restored when it holds *exactly*
the placeholder — a partially-matching value is left alone rather than guessed at.
