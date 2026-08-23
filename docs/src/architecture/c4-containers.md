# Containers

Level 2 opens up the test process. "Container" in C4 normally means a deployable unit — a
service, a database, an app. For a library the useful reading is *a thing with its own
lifecycle*: the PHP process the tests run in, the files on disk that outlive it, and the
CLI you run separately.

```mermaid
graph TB
    dev["<b>Developer</b><br/><span>Person</span>"]

    subgraph proc["Test process — one PHPUnit run"]
        sut["<b>Code Under Test</b><br/><span>PHP</span><br/><br/>Your service class, an SDK,<br/>anything that sends requests"]
        vcr["<b>VcrClient</b><br/><span>PHP — this library</span><br/><br/>PSR-18 decorator. Records on<br/>a miss, replays on a hit"]
        client["<b>Real HTTP Client</b><br/><span>Guzzle · Symfony · php-http</span><br/><br/>Any PSR-18 implementation.<br/>Only called while recording"]
        bridge["<b>PHPUnit Bridge</b><br/><span>PHP</span><br/><br/>#[UseCassette] opens and closes<br/>the session around each test"]
    end

    subgraph disk["Working tree"]
        cass[("<b>Cassette Files</b><br/><span>JSON · YAML</span><br/><br/>tests/Cassettes/**.json<br/>Committed to the repository")]
        side[("<b>Sidecar Bodies</b><br/><span>Binary files</span><br/><br/>Bodies past 1 MiB, stored<br/>beside the cassette")]
        conf["<b>http-vcr.php</b><br/><span>PHP config file</span><br/><br/>Project-wide defaults,<br/>providers, redaction rules"]
    end

    cli["<b>http-vcr CLI</b><br/><span>Symfony Console</span><br/><br/>stale · tests · providers<br/>scan-secrets · lock · unlock"]
    api["<b>Third-party HTTP API</b><br/><span>External System</span>"]

    dev -->|runs| proc
    dev -->|runs| cli
    bridge -->|"opens the session,<br/>closes it after the test"| vcr
    sut -->|"sendRequest()<br/><span>PSR-18</span>"| vcr
    vcr -->|"only on a cassette miss<br/>with recording allowed"| client
    client -->|HTTPS| api
    vcr -->|"read · append · lock"| cass
    vcr -->|"large bodies by reference"| side
    vcr -->|"read once, frozen<br/>at first request"| conf
    cli -->|"scans #[UseCassette],<br/>reads and edits"| cass
    cli -->|reads| conf

    classDef person fill:#08427b,stroke:#052e56,color:#ffffff
    classDef container fill:#438dd5,stroke:#2e6295,color:#ffffff
    classDef store fill:#438dd5,stroke:#2e6295,color:#ffffff
    classDef external fill:#999999,stroke:#6b6b6b,color:#ffffff
    classDef boundary fill:none,stroke:#444444,stroke-dasharray:5 5,color:#888888

    class dev person
    class sut,vcr,client,bridge,cli container
    class cass,side,conf store
    class api external
    class proc,disk boundary
```

## The one arrow that matters

`VcrClient → Real HTTP Client` is the only path to the network, and it is conditional. On a
cassette hit it is never taken; the response is rebuilt from the recorded snapshot through
a PSR-17 factory. That is what makes the suite deterministic — not a mock the test has to
set up, but a decorator that answers from a file.

Because the decorator sits *above* the real client, everything the client does — retries,
middleware, connection pooling — is on the far side of the recording. What lands in the
cassette is what the client returned, not what the wire carried.

## Lifecycles

The three boxes have genuinely different lifetimes, and most of the library's design
follows from that:

| Container | Lives for | Consequence |
|---|---|---|
| `VcrClient` | One instance, possibly many per test | Cheap to construct; holds no cross-test state |
| Cassette session | One test | Owns the lock, the consumption counters, the strict-mode verdict |
| Cassette file | The repository's lifetime | Must stay diffable and free of secrets |

The middle row is the subtle one. State that looks like it belongs on the client actually
belongs to the session, because the Guzzle bridge
[produces a fresh `VcrClient` per request](decisions/0011-guzzle-integrates-through-with-inner.md)
and hooks registered on one of them have to apply to all of them. See
[ADR-0006](decisions/0006-configuration-freezes-at-the-session.md) for why configuration
freezes at the session boundary rather than the object boundary.

## The CLI is a separate reader

`bin/http-vcr` runs outside the test process entirely. It never constructs a `VcrClient`;
it reads cassettes through the same serializer and finds `#[UseCassette]` declarations by
parsing test files with `nikic/php-parser` rather than by loading them. Static analysis
rather than reflection, so a test file that would fatal on load can still be inventoried.

See the [CLI Reference](../reference/cli.md) for the commands themselves.
