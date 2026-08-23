# ADR-0012: Strict mode is verified in `close()`, never in the destructor

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 42

## Context

`StrictMode::AllPlayed` fails a test whose cassette holds interactions nothing asked for;
`InOrder` fails when replay departed from the recorded sequence. Both are verdicts about a
finished run, so they can only be checked at the end.

"The end" is ambiguous in PHP. There is the moment the test method returns, and there is the
moment the garbage collector reclaims the client — which may be during another test, during
shutdown, or during the handling of an unrelated exception. Throwing from `__destruct()` at
those moments produces failures attributed to the wrong test, or fatal errors that swallow
the real one.

## Decision

`close()` releases the lock **and** checks strict mode. `__destruct()` releases the lock and
nothing else. `InOrder` is implemented as monotonicity of recorded positions, evaluated at
close.

The PHPUnit bridge closes from an `#[After]` method in the trait; the `Test\Finished`
subscriber is only a backstop for clients the trait never saw.

## Consequences

**Good.** An assertion fires at a moment the test chose, and is attributed to the test that
caused it. The lock is always given back regardless — including when the strict-mode
assertion itself fails, which has its own test. Nothing depends on collection timing.

**Bad.** A test that never calls `close()` and does not use the bridge gets no strict-mode
check at all. Silently skipping a requested assertion is unpleasant; the alternative was
raising it from a destructor, which is worse in every way that matters.
