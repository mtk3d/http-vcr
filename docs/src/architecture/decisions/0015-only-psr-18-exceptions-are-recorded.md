# ADR-0015: Only the two PSR-18 exception interfaces are recorded

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 30

## Context

With `recordTransportErrors: true`, a failed request is recorded in place of a response so
the failure replays deterministically. But "a failed request" is not a well-defined set: the
real client can throw anything at all, including bugs in its own code, and a
`TypeError` from inside Guzzle is not a transport error worth preserving as test data.

## Decision

Only exceptions implementing PSR-18's `NetworkExceptionInterface` or
`RequestExceptionInterface` are recorded, stored under an `ErrorCategory` of `Network` or
`Request`. Anything else propagates untouched and nothing is written.

On replay, http-vcr throws its **own** `VcrNetworkException` or `VcrRequestException`,
implementing the matching PSR-18 interface. It never attempts to reconstruct the original
client's exception class.

## Consequences

**Good.** The recorded set is exactly what PSR-18 defines as a transport failure, which is
portable across clients. Code catching `NetworkExceptionInterface` — the interface it should
be catching — works identically on both runs. A genuine bug in the client is not silently
frozen into a cassette.

**Bad.** Code catching `GuzzleHttp\Exception\ConnectException` by concrete class will not
catch the replayed failure. This is the correct trade: rebuilding a foreign exception class
means guessing at constructor arguments and private state, and would tie cassettes to the
client that recorded them.

**Off by default.** `recordTransportErrors` defaults to `false`, so a failure reaches the
caller and nothing is written — a transient network blip during recording does not become a
permanent fixture.
