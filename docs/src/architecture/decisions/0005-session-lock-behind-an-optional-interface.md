# ADR-0005: The session lock lives behind an optional interface

**Status:** Accepted · **Reference:** `PLAN.md` §7 decisions 19, 20

## Context

Two test processes running in parallel can both decide a cassette is missing and both start
recording into it. Whoever writes last wins, and the loser's interactions vanish — or worse,
the two interleave into a file that replays as neither run.

A lock fixes it, but not every store can take one. A filesystem can; an object store or an
in-memory persister used in the library's own tests cannot, and a store that cannot lock is
still a perfectly good store for replaying.

## Decision

`CassettePersisterInterface` stays the minimum a store must do: `read`, `write`, `delete`,
`exists`, `list`, `describe`. Locking is a second, optional interface —
`Persistence\SupportsSessionLocking` with `lock()` and `unlock()`. A persister that
implements it gets locked recording sessions; one that does not still works for playback.

The lock is held for the **whole recording session**, not just around the write.

## Consequences

**Good.** Implementing a store stays cheap. The library asks `instanceof` once and adapts.
Holding the lock for the session rather than the write is what actually prevents interleaving:
a lock taken only at write time would let two runs both read an empty cassette, both record
different interactions, and both write "correctly".

**Bad.** A parallel run against a non-locking store has no protection, and the library
cannot warn about it usefully because the same store is the right answer for replay-only
suites. The trade is documented rather than solved.

**Related.** `describe(string $key): string` is on the main interface so exception messages
can say *where* a cassette was expected — a filesystem path, an object key, whatever the
store considers a location. Without it, "cassette not found" cannot say not found *where*.
