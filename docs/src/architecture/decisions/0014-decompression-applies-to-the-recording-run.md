# ADR-0014: Decompression changes the recording run's response too

**Status:** Accepted · **Reference:** `PLAN.md` §7 decisions 28, 29

## Context

APIs return gzip. Storing the compressed bytes makes the cassette unreadable and its diffs
meaningless, so http-vcr decompresses before recording and stores readable text.

That creates a trap. If decompression happened only on the *storage* path, the recording run
would hand the code under test the original compressed response while every later run handed
it decompressed text. The suite would pass on the run that recorded and fail on the next
one — the single worst failure mode a record/replay tool can have, because it makes the
recording step look successful.

## Decision

Decompression applies to the response handed back to the caller as well, not just to what is
written. The recording run sees exactly what replaying runs will see. `Content-Length` is
corrected to describe the decompressed bytes rather than left describing the compressed
ones.

Whether a body is treated as binary is decided by `bodyEncoding` — the actual content — not
by trusting `Content-Type`.

## Consequences

**Good.** Record and replay are indistinguishable from the caller's side, which is the
property the whole library rests on. Cassettes hold readable text. Both spellings of deflate
found in the wild are accepted.

**Bad.** Code that deliberately inspects `Content-Encoding` sees something different from
what the server sent. For the case where compression *is* what is under test,
`decodeCompressedResponse: false` turns the whole behaviour off; there is a test named for
exactly that scenario.

**An encoding this build cannot decompress** — a missing zlib extension, an exotic codec — is
stored exactly as it arrived rather than half-processed or rejected.
