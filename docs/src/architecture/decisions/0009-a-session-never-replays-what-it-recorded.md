# ADR-0009: A session never replays what it just recorded

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 21

## Context

A test sends the same request twice against an empty cassette. The first send records
interaction #1. The second send now finds a matching interaction on the cassette — the one
this very run just wrote a millisecond ago.

Replaying it would mean the recording run and every run after it behave differently: the
recording run makes one real request, and the replaying run makes none but sees two
responses. A cassette recorded from a test that polls an endpoint until it changes would
capture a single response and then replay it forever, which is the opposite of what the test
observed.

## Decision

Interactions recorded during a session do not participate in matching in that same session.
The second send is another miss, makes another real request, and records interaction #2.

## Consequences

**Good.** The recording run makes exactly the requests the code under test makes, in the
same order, and the cassette is a faithful transcript. Replay reproduces the recording run
rather than an optimised version of it. Polling loops, retries, and pagination all record
correctly.

**Bad.** A test that sends the same request a hundred times records a hundred interactions
and makes a hundred real calls on the recording run. That is the honest cost of a faithful
transcript, and `repeatablePlayback` exists for the case where you would rather one
recording served the repeats.

**Interaction with `repeatablePlayback`.** When the cassette is repeatable the rule relaxes:
one recording does serve the repeats, because the user has explicitly said the interaction
is not order- or count-sensitive.
