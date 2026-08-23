# ADR-0004: Interactions are snapshots, not live PSR-7 objects

**Status:** Accepted · **Reference:** `PLAN.md` §3.2, §7 decisions 25, 26

## Context

The obvious internal representation of a recorded exchange is the PSR-7 request and
response objects themselves. It is also unworkable. A PSR-7 body is a `StreamInterface` — a
handle to something that may be a socket, may not rewind, and is very likely to be consumed
by the first thing that reads it. Holding one and expecting to serialize it later means
holding a resource whose contents have already been drained by the code under test.

## Decision

The moment a request or response passes through, it is converted to a plain snapshot:
`RecordedRequest` and `RecordedResponse`, holding strings and arrays. `Interaction` is built
through the named constructors `Interaction::recorded()` and `Interaction::failed()`, with a
private constructor so no other shape can exist.

Responses handed back to the caller are *rebuilt* from the snapshot through a PSR-17 factory
rather than stored and returned.

## Consequences

**Good.** Serialization is total — there is no stream state that might or might not survive
the trip to disk. Matching compares values, not object identity. Replay is deterministic
because the response object handed to the test is freshly constructed every time, so a test
that consumes the body does not affect the next one that replays the same interaction.

**Bad.** The library needs a PSR-17 factory to rebuild responses, which is a dependency it
cannot supply itself. `Psr17FactoryResolver` finds one in whatever the project already
installed (Guzzle, Nyholm, Laminas ship them) and fails at construction time with a clear
message if nothing is available — rather than partway through the first request.

**Also.** Non-seekable bodies are handled at the snapshot boundary: the body is buffered
once, and both the recording and the code under test get a fresh readable stream over the
buffered bytes. See [Edge Cases](../edge-cases.md#streams).
