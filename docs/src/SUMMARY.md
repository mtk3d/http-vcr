# Summary

[Introduction](introduction.md)

# Getting Started

- [Installation](getting-started/installation.md)
- [Quick Start](getting-started/quick-start.md)

# Core Concepts

- [How It Works](concepts/how-it-works.md)
- [Record Modes](concepts/record-modes.md)
- [The Cassette Format](concepts/cassette-format.md)
- [Matching Requests](concepts/matching.md)
- [Hooks](concepts/hooks.md)

# Safety

- [Redacting Sensitive Data](safety/redaction.md)
- [Locked Interactions](safety/locked-interactions.md)

# Advanced Usage

- [Strict & Sequential Mode](advanced/strict-mode.md)
- [Auto Re-record (staleAfter)](advanced/stale-after.md)
- [Scoping Cassettes by URL](advanced/scoping.md)
- [Transport Errors](advanced/transport-errors.md)
- [Storage & Formats](advanced/storage-and-formats.md)

# Framework Integration

- [Guzzle](integrations/guzzle.md)
- [Symfony HttpClient](integrations/symfony.md)
- [Laravel](integrations/laravel.md)
- [Other PSR-18 Clients](integrations/other-clients.md)
- [PHPUnit](integrations/phpunit.md)

# Examples

- [Shopify's Official PHP SDK](examples/shopify-official-sdk.md)

# Reference

- [VcrClient Reference](reference/vcr-client.md)
- [Configuration Reference](reference/configuration.md)
- [CLI Reference](reference/cli.md)
- [Environment Variables](reference/environment.md)
- [Cassette Format Reference](reference/cassette-format.md)
- [Exceptions](reference/exceptions.md)
- [Roadmap](reference/roadmap.md)

# Architecture

- [System Context](architecture/c4-context.md)
- [Containers](architecture/c4-containers.md)
- [Components](architecture/c4-components.md)
- [Edge Cases](architecture/edge-cases.md)
- [Decision Records](architecture/decisions/index.md)
    - [0001 · Decorate a PSR-18 client](architecture/decisions/0001-decorate-a-psr-18-client.md)
    - [0002 · JSON cassettes by default, YAML opt-in](architecture/decisions/0002-json-cassettes-yaml-opt-in.md)
    - [0003 · Recording is allowed locally and refused on CI](architecture/decisions/0003-recording-allowed-locally-refused-on-ci.md)
    - [0004 · Interactions are snapshots, not live PSR-7 objects](architecture/decisions/0004-interactions-are-snapshots.md)
    - [0005 · The session lock lives behind an optional interface](architecture/decisions/0005-session-lock-behind-an-optional-interface.md)
    - [0006 · Configuration freezes at the cassette session](architecture/decisions/0006-configuration-freezes-at-the-session.md)
    - [0007 · Redaction normalises both sides instead of special-casing matchers](architecture/decisions/0007-redaction-normalizes-both-sides.md)
    - [0008 · Redaction is one rule class with a target enum](architecture/decisions/0008-redaction-is-one-rule-class.md)
    - [0009 · A session never replays what it just recorded](architecture/decisions/0009-a-session-never-replays-what-it-recorded.md)
    - [0010 · Scoping splits the session into two classes](architecture/decisions/0010-scoping-splits-session-and-manager.md)
    - [0011 · Guzzle integrates through `withInner()` satellites](architecture/decisions/0011-guzzle-integrates-through-with-inner.md)
    - [0012 · Strict mode is verified in `close()`, never in the destructor](architecture/decisions/0012-strict-mode-verified-in-close.md)
    - [0013 · Large bodies move to sidecar files](architecture/decisions/0013-large-bodies-move-to-sidecar-files.md)
    - [0014 · Decompression changes the recording run's response too](architecture/decisions/0014-decompression-applies-to-the-recording-run.md)
    - [0015 · Only the two PSR-18 exception interfaces are recorded](architecture/decisions/0015-only-psr-18-exceptions-are-recorded.md)
    - [0016 · The Laravel bridge lives in its own repository](architecture/decisions/0016-laravel-bridge-in-its-own-repository.md)
    - [0017 · YAML cassettes wherever symfony/yaml is installed](architecture/decisions/0017-yaml-when-symfony-yaml-is-installed.md)
