# ADR-0003: Recording is allowed locally and refused on CI

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 5

## Context

The failure mode this library exists to prevent is a test suite that quietly reaches a real
API. If recording were simply always allowed, a missing cassette on CI would be repaired by
calling the live API — spending quota, mutating remote state, and needing production
credentials on a build agent. If recording were always *disallowed* without an explicit
opt-in, the first-run experience would be a failure and a paragraph of setup.

Those two audiences want opposite defaults, and they are reliably distinguishable.

## Decision

Recording is permitted by default, and refused when the environment looks like CI.
Detection reads `CI`, `CONTINUOUS_INTEGRATION`, `BUILD_NUMBER`, `JENKINS_URL` and
`TEAMCITY_VERSION` — which between them cover GitHub Actions, GitLab CI, CircleCI, Travis,
Buildkite, Jenkins and TeamCity.

`VCR_ALLOW_RECORDING` overrides in one direction and `RecordMode::PlaybackOnly` in the
other, so neither default is a trap.

## Consequences

**Good.** No setup on a developer machine: write the test, run it, get a cassette. On CI a
missing cassette raises `RecordingNotAllowedException`, whose message names the variable
that triggered the detection — so a false positive is diagnosable rather than mysterious.

**Bad.** The heuristic is a guess about the world, and guesses are wrong sometimes. A
developer whose shell exports `CI=1` for unrelated reasons gets a refusal they did not
expect. The mitigation is entirely in the error message: it says which variable was set and
what to do about it, rather than reporting a generic "cannot record".

**Deliberately narrow.** The variable list is short and specific rather than a broad pattern
match on anything containing `CI`. A false positive here blocks work; a false negative only
means a build agent records once, which the missing credentials would stop anyway.
