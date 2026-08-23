# ADR-0010: Scoping splits the session into two classes

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 44

## Context

Scoping lets one cassette name span several files — keyed by URL, by tenant, by whatever a
resolver returns. `tests/Cassettes/orders.json` becomes `orders.shopify.json` and
`orders.stripe.json`, chosen per request.

That breaks the assumption that "the cassette" is one thing. Some state belongs to the test
(the hook pipeline, the redaction rules, whether the first request has gone out) and some
belongs to each file separately (the lock, which interactions have been consumed, the
strict-mode verdict).

## Decision

Two classes. `CassetteSession` is the cassette *as the test named it*: it routes requests to
files and owns the test-scoped state. `CassetteManager` is *one file*: it owns the file-scoped
state, one instance per scope, and the instances are independent down to the lock.

Without a scope resolver there is exactly one manager and the session is a thin front.

## Consequences

**Good.** Strict mode is checked per file, so "this cassette has an interaction nothing
asked for" names the file the leftover is actually in. Locks are per file, so recording into
one scope does not block another. Hooks stay per test, which is what a reader expects when
they register one.

**Bad.** Two classes where a smaller library would have one, and the split is invisible
until you use scoping. The alternative — pooling consumption counters across scopes — was
rejected because it makes strict-mode failures unactionable: it can tell you something was
left over, but not where.

**Scope names become filenames**, so they are sanitised into a single path segment. A scope
that cannot be one is refused at resolution time rather than mangled into a surprising path.
