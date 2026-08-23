# ADR-0011: Guzzle integrates through `withInner()` satellites

**Status:** Accepted · **Reference:** `PLAN.md` §7 decisions 8, 46, 47

## Context

Guzzle middleware sits inside a `HandlerStack`, and the handler it wraps is supplied *per
request* rather than at construction. A `VcrClient` built the ordinary way already holds its
inner client, so it does not fit: the middleware knows the real handler only when a request
is already in flight.

## Decision

`VcrClient::withInner(ClientInterface $inner)` returns a cloned satellite instance with the
supplied client and a **shared cassette session**. The middleware makes one per request.

The satellite's destructor does not close the session — only the instance that owns it does.
The middleware returns a rejected promise rather than throwing, because that is what
Guzzle's stack expects, and its position in the stack is part of the contract.

## Consequences

**Good.** Consumption counters, the lock, hooks and redaction all live on the session, so
they behave identically whether the traffic came through one client or fifty satellites. A
cassette recorded through the middleware is indistinguishable from one recorded directly.

**Bad.** "Cloned instance sharing mutable state with its parent" is a shape that has to be
held carefully — it is exactly why session-scoped state was pulled off `VcrClient` in
[ADR-0006](0006-configuration-freezes-at-the-session.md) and
[ADR-0010](0010-scoping-splits-session-and-manager.md). A satellite going out of scope
mid-test must not release the lock, and there is a test asserting precisely that.

**Position matters.** The middleware has to sit where it sees the request the handler would
send. Documented in [the Guzzle integration page](../../integrations/guzzle.md) rather than
left for users to discover through a confusing cassette.
