# Storage & Formats

Two separate questions, answered by two separate interfaces: **where** a cassette lives (the persister) and **what shape** it has on disk (the serializer). Neither is something most projects need to touch — the defaults are the filesystem and JSON — but both are swappable, and the split is what keeps a compressed or database-backed store from needing any change in the record/replay core.

```php
new VcrClient(
    $inner,
    cassette: 'shopify/get-product',
    persister: new FilesystemCassettePersister(),
    serializer: new JsonCassetteSerializer(),
);
```

Set either one project-wide in [`http-vcr.php`](../reference/configuration.md) instead, if it should apply everywhere.

## Serializers

Two serializers are **canonical**, meaning they carry http-vcr's full domain model — `schemaVersion`, `outcome`, `bodyRef`, `repeatablePlayback`, `locked` — and are supported across the whole cassette lifecycle, schema migration included.

| Serializer | Extension | Notes |
|---|---|---|
| `JsonCassetteSerializer` | `.json` | Default. Readable diffs in a pull request, no dependencies. |
| `YamlCassetteSerializer` | `.yaml` | Opt-in, requires `symfony/yaml`. The convention teams coming from Ruby VCR, vcrpy, or go-vcr will recognize. |

```php
new VcrClient($inner, cassette: 'shopify/get-product', serializer: new YamlCassetteSerializer());
```

The interface is small:

```php
interface CassetteSerializerInterface {
    public function serialize(Cassette $cassette, ?SidecarBodies $bodies = null): string;
    public function deserialize(string $content, ?SidecarBodies $bodies = null): Cassette;
    public function fileExtension(): string;   // 'json', 'yaml' — no leading dot
}
```

`$bodies` is where bodies past the [inline threshold](../reference/cassette-format.md#sidecar-files) go, since a body large enough to leave the file has to be written somewhere; passing `null` keeps every body inline whatever its size. The cassette manager supplies one, because it is what knows which cassette file is open and therefore what the sidecars beside it are called.

The unit of exchange is a `Cassette` — a `schemaVersion` plus a list of `Interaction`s, with no I/O of its own — rather than a bare array of interactions. That's what lets a serializer carry the version in both directions: `schemaVersion` is a property of the file, not of any interaction, so a serializer that only ever saw a list would have to emit the version from a hardcoded constant and throw it away on read, leaving nowhere for the [migration path](#format-versioning) to live.

`fileExtension()` exists because the persister deliberately knows nothing about formats: it stores bytes under a key, and it's the cassette manager that turns a cassette name plus a serializer's extension into that key.

## Persisters

```php
interface CassettePersisterInterface {
    public function read(string $key): ?string;
    public function write(string $key, string $content): void;
    public function delete(string $key): void;
    public function exists(string $key): bool;
    /** @return iterable<string> names (without extension) of cassettes stored in that format */
    public function list(string $extension, string $prefix = ''): iterable;
}
```

A `$key` includes the format's file extension; the names `list()` returns don't. The extension is passed *in* rather than known by the persister, since a persister stores keyed bytes and has no idea which serializer is in play — the caller (the cassette manager, or the CLI) always has one at hand.

`FilesystemCassettePersister` is the default: one file per cassette, under `cassetteDirectory`, with the cassette name as a relative path inside it.

A few notes for anyone writing their own:

- `delete()` isn't optional. Three documented behaviors need it: cleaning up orphaned [sidecars](../reference/cassette-format.md#sidecar-files), removing a sidecar when a body shrinks below the inline threshold, and tidying up after a failed write.
- `list()` must return only entries stored under the extension it was given — sidecar `.bin` files and `.cassette-lock` files go through the same persister, and `stale`/`scan-secrets` would otherwise try to deserialize raw bytes as a cassette.
- A persister that can't meaningfully enumerate (a database-backed one, say) returns an empty iterator from `list()`. The CLI then reports "this persister doesn't support enumeration" rather than silently finding nothing.
- Adding a *storage* variation doesn't need a new persister at all: a decorator around an existing one — a `GzipCassettePersister` compressing on the way through, for instance — composes cleanly, because the contract is just keyed bytes.

Concurrency, atomic writes, and the lock-file mechanism are the filesystem persister's business and are documented in the [Cassette Format Reference](../reference/cassette-format.md#lock-files).

## HAR: import and export, not a storage format

HAR (HTTP Archive) is an open standard and genuinely useful for exchanging traffic with tools outside http-vcr — the Network tab in Chrome or Firefox DevTools, Postman, Charles Proxy. It's supported for exactly that, and it is deliberately **not** a `CassetteSerializerInterface`:

```php
use HttpVcr\Import\HarCassetteImporter;
use HttpVcr\Import\HarCassetteExporter;

(new HarCassetteImporter())->import('captured.har', 'shopify/get-product');
(new HarCassetteExporter())->export('shopify/get-product', 'shopify.har');
```

Import converts a HAR capture into a JSON cassette — a one-off starting point, usually to avoid recording something by hand. Export goes the other way, for sharing with external tooling.

The reason it isn't a serializer: HAR has no natural home for concepts specific to http-vcr — `schemaVersion`, `outcome: "error"` for a [transport failure](transport-errors.md), `bodyRef`, `repeatablePlayback`, `staleAfter`/`recordedAt`. Forcing them into someone else's archival standard would mean either quietly departing from the HAR spec or trimming http-vcr down to the intersection of the two. Import and export accept that a round trip through HAR is lossy for those fields, which is fine for an interchange format and would not be fine for the file a test suite depends on. (Polly.js, in the JS ecosystem, does use HAR as its storage format — a deliberate difference, and one it can afford because its model has no equivalent of those fields.)

## Format versioning

Every serialized cassette carries a top-level `schemaVersion`, starting at `1`. Cassettes live a long time in a repository, so migration has to be possible from day one rather than retrofitted: `deserialize()` checks the version, throws `CassetteFormatException` with an "upgrade http-vcr" message on anything newer than it understands, and runs older-but-supported versions through an incremental, per-field upgrade path before the rest of the core sees them.

HAR keeps its own external standard and isn't versioned by http-vcr.
