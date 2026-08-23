# ADR-0013: Large bodies move to sidecar files

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 27

## Context

Cassettes are committed and read in review. A recorded PDF, image or multi-megabyte JSON
export inlined as base64 turns the file into an unreviewable wall of characters, and every
re-record produces a diff nothing can read.

## Decision

Bodies past `inlineBodyLimit` (default **1 MiB**) are written to a file of their own beside
the cassette, and the cassette holds a reference. The mechanism is an optional
`?SidecarBodies` argument on `serialize()` and `deserialize()` — a serializer that is handed
one uses it, and one that is not still round-trips a complete cassette.

Bodies are content-addressed, so two interactions with identical bodies share one file.
Sidecars nothing references any more are removed when the cassette is written.

## Consequences

**Good.** The cassette stays readable no matter what the API returned. Byte-for-byte replay
is preserved. Deduplication means a paginated recording of the same large payload costs one
file. Garbage collection on write means the directory does not grow forever.

**Bad.** A cassette is now potentially several files, and moving one by hand without the
others breaks it. Two safeguards: a sidecar whose contents no longer match its reference is
**refused rather than replayed as wrong bytes**, and a missing one produces an error naming
the file that is gone. Body files are also recognisably not cassettes, so the CLI's
inventory does not mistake them for one.

**Optional by design.** Passing the sidecar store as an argument rather than baking it into
the serializer interface keeps custom serializers simple — they can ignore the feature and
still be correct.
