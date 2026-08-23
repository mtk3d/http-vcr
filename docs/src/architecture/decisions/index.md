# Decision Records

Architecture decision records: what was decided, what the alternative was, and what it costs.
Each one is short and each one names a trade-off — an ADR with no downside section is a
press release, not a record.

These sixteen are the decisions that shape the structure of the library and would be
expensive to reverse. They are not the complete set: `PLAN.md` §7 holds sixty-four resolved
decisions, most of which are API details rather than architecture (whether a configuring
method returns `void` or `self`, how a particular exception factory is spelled). Each ADR
below cites the §7 numbers it comes from, so the fuller reasoning is one hop away.

| # | Decision | Shapes |
|---|---|---|
| [0001](0001-decorate-a-psr-18-client.md) | Decorate a PSR-18 client | The whole library |
| [0002](0002-json-cassettes-yaml-opt-in.md) | JSON cassettes, YAML opt-in | On-disk format |
| [0003](0003-recording-allowed-locally-refused-on-ci.md) | Recording allowed locally, refused on CI | Safety default |
| [0004](0004-interactions-are-snapshots.md) | Interactions are snapshots, not PSR-7 objects | Core data model |
| [0005](0005-session-lock-behind-an-optional-interface.md) | Session lock behind an optional interface | Persistence contract |
| [0006](0006-configuration-freezes-at-the-session.md) | Configuration freezes at the session | Lifecycle |
| [0007](0007-redaction-normalizes-both-sides.md) | Redaction normalises both sides | Matching + redaction |
| [0008](0008-redaction-is-one-rule-class.md) | Redaction is one rule class with a target enum | Hook pipeline |
| [0009](0009-a-session-never-replays-what-it-recorded.md) | A session never replays what it just recorded | Recording semantics |
| [0010](0010-scoping-splits-session-and-manager.md) | Scoping splits session and manager | Cassette identity |
| [0011](0011-guzzle-integrates-through-with-inner.md) | Guzzle integrates through `withInner()` satellites | Bridges |
| [0012](0012-strict-mode-verified-in-close.md) | Strict mode verified in `close()`, never `__destruct` | Lifecycle |
| [0013](0013-large-bodies-move-to-sidecar-files.md) | Large bodies move to sidecar files | Storage |
| [0014](0014-decompression-applies-to-the-recording-run.md) | Decompression applies to the recording run too | Record/replay parity |
| [0015](0015-only-psr-18-exceptions-are-recorded.md) | Only PSR-18 exception interfaces are recorded | Transport errors |
| [0016](0016-laravel-bridge-in-its-own-repository.md) | Laravel bridge in its own repository | Packaging |

## The thread running through them

Several of these are the same decision applied in different places. **Record and replay must
be indistinguishable from the caller's side** produces
[0014](0014-decompression-applies-to-the-recording-run.md) (decompress on both runs),
[0004](0004-interactions-are-snapshots.md) (rebuild responses rather than hand back stored
ones) and [0009](0009-a-session-never-replays-what-it-recorded.md) (the recording run makes
the requests the code actually makes).

**State belongs to the session, not the object** produces
[0006](0006-configuration-freezes-at-the-session.md),
[0010](0010-scoping-splits-session-and-manager.md) and
[0011](0011-guzzle-integrates-through-with-inner.md) — all three fall out of the bridges
needing to construct clients freely without fragmenting the run.

**Optional capability goes in a second interface** produces
[0005](0005-session-lock-behind-an-optional-interface.md) and the `ExplainsMismatch`
treatment of matcher diagnostics: the minimum contract stays implementable, and the extra is
detected with `instanceof`.

## Writing a new one

New work is either a `PLAN.md` §8 idea being promoted — in which case write the decision in
§7 first — or a correction to something already here. If the change alters one of the
sixteen above, amend that ADR with a `Superseded by` line rather than editing the decision
out of history.
