# Edge Cases

The awkward inputs a record/replay library meets in practice, and what http-vcr does with
each. Every behaviour on this page has a test in `tests/Integration/` named after it — if a
row here and the code ever disagree, the test is the one telling the truth.

## Streams

PSR-7 bodies are streams, and streams are not obliged to rewind. A request body built from a
socket, a pipe, or `php://input` can be read exactly once.

| Situation | Behaviour |
|---|---|
| Request body cannot be rewound | Buffered once at the snapshot boundary. Both the recording and the real client get a readable stream over the buffered bytes, so the request still reaches the network intact |
| Response body cannot be rewound | Buffered the same way. The code under test gets a fresh readable stream, not the drained original |
| Body is seekable | Handed back as the same stream, rewound — no needless copy |

This is the practical consequence of
[ADR-0004](decisions/0004-interactions-are-snapshots.md): everything becomes a string at the
boundary, so nothing downstream can be surprised by a consumed handle.

## Bodies

| Situation | Behaviour |
|---|---|
| Binary response (PDF, image, gzip under test) | Stored base64-encoded, flagged in `bodyEncoding` |
| Text | Left readable in the file, so diffs stay reviewable |
| Claims to be text but is not valid UTF-8 | Treated as binary. The decision is made on the **content**, not on `Content-Type` |
| Binary request body | Encoded too — the rule is not response-only |
| Empty body | Never encoded. An empty string stays an empty string |

## Large bodies and sidecar files

Past `inlineBodyLimit` (default 1 MiB) the body moves to a file of its own — see
[ADR-0013](decisions/0013-large-bodies-move-to-sidecar-files.md).

| Situation | Behaviour |
|---|---|
| Body over the threshold | Written beside the cassette; the cassette keeps a reference |
| Replay of a sidecar body | Byte-for-byte identical to what was recorded |
| Body under the threshold | Stays inline in the cassette |
| Two interactions, identical bodies | Share one file — content-addressed, stored once |
| Sidecar no longer referenced | Removed when the cassette is next written |
| Sidecar edited by hand | **Refused**, not replayed as wrong bytes |
| Sidecar missing | Error naming the file that is gone |
| Body files in the cassette directory | Recognised as not-cassettes; the CLI inventory skips them |

## Compression

| Situation | Behaviour |
|---|---|
| Gzipped response | Decompressed and stored as readable text |
| The recording run itself | Sees the **same decompressed response** the replaying run will see — [ADR-0014](decisions/0014-decompression-applies-to-the-recording-run.md) |
| `Content-Length` | Corrected to describe the decompressed bytes |
| `deflate` | Both spellings found in the wild are accepted |
| Compression is what you are testing | `decodeCompressedResponse: false` turns it all off |
| Encoding this build cannot decompress | Stored exactly as it arrived — not half-processed, not rejected |

## Transport errors

Off by default: a failure reaches the caller and nothing is written. With
`recordTransportErrors: true`:

| Situation | Behaviour |
|---|---|
| PSR-18 network failure | Recorded in place of a response, category `Network` |
| PSR-18 request failure | Recorded, category `Request` |
| Replay | Throws http-vcr's own exception implementing the matching PSR-18 interface |
| The original client's exception class | **Never** reconstructed — [ADR-0015](decisions/0015-only-psr-18-exceptions-are-recorded.md) |
| An exception that is neither kind | Not recorded. It propagates untouched |
| A recorded failure on replay | Consumed like any other interaction |

## Repeats and consumption

| Situation | Behaviour |
|---|---|
| Same request twice, two recordings | Replayed in the order they were made |
| Asking once more than was recorded | Fails, saying the cassette is exhausted |
| Two `VcrClient` instances | Separate sessions — they do not share consumption |
| `repeatablePlayback` | One interaction answers as often as it is asked |
| A single interaction marked repeatable in the data | Same, for that interaction only |
| A recording session asking twice | Records twice; it never replays what it just recorded — [ADR-0009](decisions/0009-a-session-never-replays-what-it-recorded.md) |
| …unless the cassette is repeatable | Then one recording serves the repeats |

## Strict mode

| Situation | Behaviour |
|---|---|
| `AllPlayed`, everything replayed | Passes |
| `AllPlayed`, leftovers | Fails, **naming** the interactions nothing asked for |
| `AllPlayed` on a cassette the test never touched | Fails |
| A repeatable interaction | Counts as played once it has been replayed at all |
| `InOrder`, sequence matches | Passes |
| `InOrder`, out of order | Fails, naming the pair that came out backwards |
| `InOrder`, something missing entirely | Ignored — that is `AllPlayed`'s job, not ordering's |
| What the session recorded itself | Not judged |
| The assertion fails | The lock is still given back — [ADR-0012](decisions/0012-strict-mode-verified-in-close.md) |

## Staleness

| Situation | Behaviour |
|---|---|
| Stale cassette, no enforcement | Replays as usual |
| `VCR_ENFORCE_STALE_CHECK` set | The same cassette becomes a failure, naming the interaction |
| Ignore-stale set as well | Ignoring outranks enforcement |
| Inside the threshold | Passes under enforcement |
| Mixed ages | Only the interaction that outlived the threshold is reported |
| First recording | Enforcement has nothing to say about it |
| Two tests declaring different `staleAfter` for one cassette | Reported and skipped, not silently resolved |

## Scoping

| Situation | Behaviour |
|---|---|
| A scope is resolved | It becomes part of the filename |
| Two scopes of one cassette | Separate files, independent locks and counters |
| A scope already on disk | Replays with no real request |
| `PlaybackOnly` with a missing scope | Error lists the scopes that **do** exist |
| Recording blocked | Blames the variable *and* still lists the scopes |
| A request the resolver does not scope | Uses the cassette's own file |
| Strict mode | Checked per scope file, not over one pool |
| A scope that cannot be a filename | **Refused**, not mangled into a surprising path |
| A scope that can be sanitised | Reduced to a single path segment |

## Credentials

| Situation | Behaviour |
|---|---|
| A provider key is missing while recording | The request is stopped **before it goes out** |
| Only one API being recorded | Only that API's credentials are required |
| A replaying run | Never asks for credentials it will not use |
| A cassette requiring its own variable | Works with no provider involved |
| Several missing at once | Reported together, not one run at a time |

## Redaction and matching

The subtle one: redaction is applied to the incoming request too, so both sides match
placeholder-to-placeholder — [ADR-0007](decisions/0007-redaction-normalizes-both-sides.md).

| Situation | Behaviour |
|---|---|
| A secret | Never reaches the cassette file |
| A two-way rule | Gives the code under test the real value back from the response |
| A redacted header, query param or form field | Still matches on replay |
| The recording run | Still sees the real response |
| `Authorization` | Redacted with no configuration at all |
| An auto-redacted header | Stops telling two otherwise-identical requests apart |
| `includeSensitiveHeaders()` | Stores it as sent, so it tells them apart again |
| A project-wide rule in `http-vcr.php` | Applies without touching the client |

## Lifecycle

| Situation | Behaviour |
|---|---|
| Configuring after the first request | `LogicException` naming the method — [ADR-0006](decisions/0006-configuration-freezes-at-the-session.md) |
| A satellite from `withInner()` going out of scope | Does **not** end the session |
| A request through a satellite | Freezes configuration for the whole session |
| `close()` | Releases the lock and checks strict mode |
| `__destruct()` | Releases the lock only, never asserts |

## Forced re-recording (`VCR_ERASE_TAPE`)

| Situation | Behaviour |
|---|---|
| A named cassette | Recorded from scratch |
| A cassette the selector does not name | Replayed as usual |
| A locked interaction | Spared, and keeps being replayed |
| A provider name | Selects every host it covers, leaving other APIs in the same cassette alone |
| Survivors | Keep their order at the front; fresh recordings follow |
| A fully locked cassette | Left exactly as it was, and says the erase came to nothing |
| Recording disabled | The cassette is left alone rather than erased |
| A bad value for the variable | `InvalidArgumentException` — not a new exception type |
