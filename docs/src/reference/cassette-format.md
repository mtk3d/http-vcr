# Cassette Format Reference

## Top-level fields

| Field | Type | Meaning |
|---|---|---|
| `schemaVersion` | `int` | Format version, starting at `1`. An unknown or newer version throws rather than attempting to parse an unrecognized shape; an older, still-supported version goes through an incremental, per-field upgrade path. |
| `interactions` | `array` | The recorded interactions, in recording order — order is significant for [`StrictMode::InOrder`](../advanced/strict-mode.md). |

## Per-interaction fields

| Field | Type | Meaning |
|---|---|---|
| `request.method` | `string` | HTTP method. |
| `request.uri` | `string` | Full URI. |
| `request.headers` | `array<string, string[]>` | Header names as recorded, values as a list — supports repeated headers. |
| `request.body` | `string` | Request body, or `""`. See the body-encoding fields below. |
| `response.status` | `int` | HTTP status code. |
| `response.headers` | `array<string, string[]>` | Same shape as request headers. |
| `response.body` | `string` | Response body. The whole `response` object is absent when `outcome` is `"error"` — there was no response. |
| `outcome` | `"success"` \| `"error"` | `"error"` for a recorded [transport failure](../advanced/transport-errors.md); `"success"` otherwise. |
| `errorCategory` | `"network"` \| `"request"` (absent for `"success"`) | Which PSR-18 exception interface the original failure implemented — `NetworkExceptionInterface` or `RequestExceptionInterface`. Determines whether replay throws `VcrNetworkException` or `VcrRequestException`. |
| `errorMessage` | `string` (absent for `"success"`) | The original exception's message, replayed into http-vcr's own exception. Subject to [redaction](../safety/redaction.md) like any other stored field — client exception messages tend to quote the full request URL. |
| `errorClass` | `string` (absent for `"success"`) | The original exception's class name (e.g. `GuzzleHttp\Exception\ConnectException`) — diagnostic metadata only, never used to reconstruct that class on replay. See [Transport Errors](../advanced/transport-errors.md). |
| `recordedAt` | `string` (ISO 8601) | When this interaction was recorded — used by [`staleAfter`](../advanced/stale-after.md). |
| `locked` | `bool` | See [Locked Interactions](../safety/locked-interactions.md). |
| `repeatablePlayback` | `bool` | When `true`, this interaction isn't "consumed" on replay — see [Record Modes](../concepts/record-modes.md). |

## Body encoding fields

These three live **inside** `request` and `response`, not at the interaction level — one interaction has two bodies, and they're routinely of different kinds (a small text request alongside a large binary response is just "downloading a file"). So the canonical paths are `request.bodyEncoding`, `response.bodyRef`, and so on.

| Field | Type | Meaning |
|---|---|---|
| `bodyEncoding` | `"base64"` (absent for text) | Present when an **inline** body is binary. Detected from `Content-Type`: `text/*`, `application/json`, `application/*+json` and `application/x-www-form-urlencoded` are treated as text, everything else as binary. Never present together with `bodyRef`. |
| `bodyRef` | `string` (absent for inline bodies) | The 16-character content hash identifying a sidecar file, for bodies over the inline size threshold (1 MiB by default). Mutually exclusive with `bodyEncoding` — a sidecar holds raw bytes, so there's nothing for base64 to solve. When present, `body` is absent. |
| `bodySha256` | `string` (absent for inline bodies) | Full SHA-256 of the sidecar body, checked against the sidecar's actual content on read — a mismatch throws `CassetteIntegrityException` instead of silently returning the wrong bytes. |

`bodyRef` holds the hash alone, not the sidecar's full filename: the filename is derived from the hash plus the name of the cassette file currently open. Storing the full name would duplicate, inside the data, the name of the file containing that data — so renaming a cassette or changing its [scope](../advanced/scoping.md) would invalidate every reference in it, even though the sidecars get renamed alongside and still match.

## Sidecar files

Bodies over the inline threshold are written next to the cassette, named after a hash of their content:

```
{cassette}.{sha256(body)[0:16]}.bin
```

Content-hash naming — rather than positional, e.g. `{cassette}.0.bin` — means reordering interactions in the cassette by hand never breaks a `bodyRef`, and identical bodies across interactions are automatically deduplicated to a single sidecar file.

`{cassette}` here is the name of the cassette file actually in use, **scope suffix included** and format extension excluded: a sidecar of `get-product.2024-01.yaml` is `get-product.2024-01.{hash}.bin`. Without the scope, two [scopes](../advanced/scoping.md) of one base cassette would share a sidecar namespace, and deleting one scope could take files the other still needs.

Sidecars are written through the same persister as the cassette itself, so the same name sanitization and locking rules apply. `CassettePersisterInterface::list()` only returns entries matching the serializer's own extension, so sidecars and lock files never show up there — otherwise commands like `stale` would try to deserialize raw bytes as a cassette.

Sidecars that nothing references any more — after a forced re-record, after an interaction is deleted by hand, after a body shrinks below the threshold — are removed when the cassette is next written. Deduplication makes that safe: a file only disappears once its last reference does.

## Lock files

While a session is recording, http-vcr holds an exclusive lock so two parallel test processes (paratest and friends) can't interleave their writes into one cassette. The lock lives on a separate `{cassette}.cassette-lock` file, not on the cassette itself — cassettes are replaced via an atomic `rename()`, which swaps the file's inode, and a lock held on an inode that's no longer at that path stops excluding anything. The lock file is empty and created on demand, in a `.http-vcr/` directory inside the cassette directory:

```
tests/Cassettes/
├── .http-vcr/
│   ├── .gitignore                              (holds `*`)
│   └── shopify/get-product.cassette-lock
└── shopify/get-product.yaml
```

That directory carries its own `.gitignore`, so lock files stay out of version control with nothing to configure and nothing added to the project's own ignore rules. They stay on disk once a session ends, which is why they live somewhere out of the way rather than beside the recordings: deleting one would reopen the race the separate file exists to avoid, since a process waiting on the lock would acquire it on an inode no longer at that path while the next process created a fresh one.

It sits next to the cassette rather than in the system temp directory, which matters more than it looks: `/tmp` isn't shared across a container boundary. A suite running inside Docker and another run started on the host see the same cassette directory through a bind mount but two different `/tmp`s — they'd take locks on two separate files and never exclude each other. The lock has to live where the resource lives, because that path is the only one every process allowed to write the cassette can agree on.

Replaying takes no lock at all. The atomic rename already guarantees a reader sees either the whole old file or the whole new one, never a half-written mix — which also means a normal CI run needs no write access to the cassette directory.

`RecordIfAbsent` decides between recording and replaying based on whether the file exists, and that check happens *under* the lock, not before it: two parallel processes starting the same not-yet-recorded test would otherwise both see nothing, both take the recording branch, and the second would append a duplicate of what the first had just recorded. So the sequence is take the lock, re-check, and — if the cassette appeared in the meantime — release it and carry on as an ordinary replaying session.

## Write pipeline order

Three things operate on the same body content and have to run in a fixed order, or redaction could receive still-compressed bytes it can't process, or a secret could reach a sidecar file before it's been redacted:

1. decompression (`Content-Encoding` stripped, body is plain text)
2. `beforeRecord` hooks, including [redaction](../safety/redaction.md) — always on the full, inline content
3. the `inlineBodyLimit` check and sidecar write, on the already-redacted content
4. serialization to disk (locked and written atomically)

`redactJsonField`/`redactFormField` behave identically whether an interaction ends up inline or in a sidecar — the sidecar decision is made on content that's already been redacted.
