# Components

Level 3 opens up the library itself. Every box here is a class or a small cluster of them
in `src/`.

```mermaid
graph TB
    sut["<b>Code Under Test</b><br/><span>External</span>"]
    inner["<b>Real PSR-18 Client</b><br/><span>External</span>"]

    subgraph core["http-vcr"]
        vcr["<b>VcrClient</b><br/><span>src/VcrClient.php</span><br/><br/>PSR-18 entry point. Snapshots the<br/>request, rebuilds the response,<br/>decompresses, base64-encodes"]
        session["<b>CassetteSession</b><br/><span>src/Cassette/</span><br/><br/>The cassette as the test names it.<br/>Routes to a file per scope; owns<br/>hooks and the started flag"]
        manager["<b>CassetteManager</b><br/><span>src/Cassette/</span><br/><br/>One cassette file. Consumption<br/>counters, the lock, record<br/>permission, strict-mode verdict"]
        matcher["<b>Matchers</b><br/><span>src/Matching/</span><br/><br/>CompositeMatcher over Method,<br/>Uri, QueryString by default.<br/>Explains its own mismatches"]
        hooks["<b>HookRegistry</b><br/><span>src/Hook/</span><br/><br/>beforeRecord / beforePlayback<br/>in registration order"]
        redact["<b>RedactionHooks</b><br/><span>src/Hook/</span><br/><br/>Always the first hook in both<br/>directions. Header, JSON field,<br/>query param, form field, value"]
        ser["<b>Serializer</b><br/><span>src/Serializer/</span><br/><br/>ArrayCassetteSerializer holds the<br/>schema; JSON and YAML are two<br/>spellings of it"]
        pers["<b>Persister</b><br/><span>src/Persistence/</span><br/><br/>Filesystem store, atomic rename,<br/>session lock, sidecar bodies"]
        env["<b>Environment</b><br/><span>src/Environment.php</span><br/><br/>CI detection, VCR_* variables,<br/>provider credential checks"]
        conf["<b>Config</b><br/><span>src/Config.php</span><br/><br/>http-vcr.php merged field by<br/>field. Frozen on first use"]
        scope["<b>Scope Resolver</b><br/><span>src/Scope/</span><br/><br/>Turns a request into a scope,<br/>which becomes part of the filename"]
        psr17["<b>Psr17FactoryResolver</b><br/><span>src/Psr17FactoryResolver.php</span><br/><br/>Finds a response/stream factory<br/>in whatever the project installed"]
        scan["<b>SecretScanner</b><br/><span>src/SecretScanner.php</span><br/><br/>Warns after a recording session<br/>if a value looks like a credential"]
    end

    store[("<b>Cassette Files</b>")]

    sut -->|sendRequest| vcr
    vcr -->|begin · for · close| session
    session -->|"one per scope"| manager
    session -->|routes with| scope
    session -->|owns| hooks
    hooks -->|"registered first"| redact
    manager -->|"asks for a match"| matcher
    manager -->|"read · write"| ser
    ser -->|bytes| pers
    pers -->|files| store
    manager -->|"may this run record?"| env
    vcr -->|"defaults from"| conf
    vcr -->|"rebuild response"| psr17
    vcr -->|"real request on a miss"| inner
    manager -->|"warns through"| scan

    classDef comp fill:#438dd5,stroke:#2e6295,color:#ffffff
    classDef external fill:#999999,stroke:#6b6b6b,color:#ffffff
    classDef store fill:#438dd5,stroke:#2e6295,color:#ffffff
    classDef boundary fill:none,stroke:#444444,stroke-dasharray:5 5,color:#888888

    class vcr,session,manager,matcher,hooks,redact,ser,pers,env,conf,scope,psr17,scan comp
    class sut,inner external
    class store store
    class core boundary
```

## Why the session and the manager are two classes

`CassetteSession` is *the name the test used*. `CassetteManager` is *one file*. Without
scoping those are the same thing and the session is a thin front. With
[a scope resolver](../advanced/scoping.md) one name spans a file per scope, and the two
responsibilities come apart: the hook pipeline and the redaction rules belong to the test,
while the lock, the consumption counters and the strict-mode verdict belong to each file
separately. Pooling the latter across scopes would hide which file a leftover interaction
was actually in — [ADR-0010](decisions/0010-scoping-splits-session-and-manager.md).

## A request, end to end

The dynamic view. This is one `sendRequest()` call, on a cassette that already has a
matching interaction:

```mermaid
sequenceDiagram
    participant SUT as Code Under Test
    participant VCR as VcrClient
    participant S as CassetteSession
    participant M as CassetteManager
    participant H as HookRegistry
    participant P as Persister

    SUT->>VCR: sendRequest(request)
    VCR->>S: begin()
    Note over S: config freezes here
    VCR->>VCR: snapshot(request)
    Note over VCR: body buffered, headers<br/>normalised, RecordedRequest built
    VCR->>S: for(request)
    S->>M: resolve scope → this file
    M->>P: read cassette (first request only)
    P-->>M: bytes
    VCR->>M: play(incoming)
    M->>M: match against unconsumed interactions
    M->>H: beforePlayback(interaction)
    Note over H: redaction restores<br/>two-way placeholders
    H-->>M: interaction
    M-->>VCR: interaction
    VCR->>VCR: rebuild response via PSR-17
    VCR-->>SUT: ResponseInterface
```

On a **miss** the tail differs: `CassetteManager` reports whether recording is allowed, and
`VcrClient` either sends through the real client and appends the result — passing it through
`beforeRecord`, where redaction strips secrets before anything reaches the serializer — or
throws. Which exception depends on why:
`RecordingNotAllowedException` when the environment forbade it,
`CassetteNotFoundException` when there is no file, and
`NoMatchingInteractionException` when the file exists but nothing in it matched. The last
one carries the mismatch explanations the matchers produced, so the message says *which
field differed*, not just that nothing matched.

## Extension points

Four interfaces are meant to be implemented from outside:

| Interface | For |
|---|---|
| `Matching\RequestMatcherInterface` | Deciding whether a recorded request is *this* request. Add `ExplainsMismatch` to get your reason into the failure message |
| `Persistence\CassettePersisterInterface` | Storing cassettes somewhere other than the filesystem. Add `SupportsSessionLocking` if the store can hold an exclusive lock |
| `Serializer\CassetteSerializerInterface` | A different on-disk spelling of the same schema |
| `Scope\CassetteScopeResolverInterface` | Splitting one cassette name across several files |

The two "add this second interface" rows are deliberate. Both capabilities are things a
store or a matcher may genuinely be unable to provide, and requiring them on the main
interface would make perfectly good implementations impossible —
[ADR-0005](decisions/0005-session-lock-behind-an-optional-interface.md).
