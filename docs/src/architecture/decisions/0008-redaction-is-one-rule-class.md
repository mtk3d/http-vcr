# ADR-0008: Redaction is one rule class with a target enum

**Status:** Accepted · **Reference:** `PLAN.md` §7 decisions 36, 37, 39

## Context

There are five things you can redact: a raw value anywhere, a header, a JSON field by
pointer, a query parameter, and a form field. The instinct is a class per kind —
`HeaderRedaction`, `JsonFieldRedaction`, and so on, behind a common interface.

They differ only in *where they look*. The matching, the placeholder substitution, and the
two-way restore are identical in all five.

## Decision

One `Redaction` class holding a `RedactionTarget` enum: `Value`, `Header`, `JsonField`,
`QueryParam`, `FormField`. The differences are a `match` arm, not a subclass.

Redaction registers itself into `HookRegistry` when the session is created, which makes it
the **first hook in both directions** — before any user `beforeRecord` hook on the way to
disk, and before any `beforePlayback` hook on the way back.

## Consequences

**Good.** Adding a target is one enum case and one arm. Ordering is a property of *when
registration happens* rather than a priority number someone has to reason about — and the
ordering is the one that matters: a user hook inspecting an interaction on its way to disk
sees it already redacted, so a hook cannot accidentally leak a secret it was never meant to
see.

**Bad.** The class carries a small amount of per-target branching, and a target needing
genuinely different substitution logic would strain the shape. None of the five do.

**On JSON fields.** A redacted JSON field is substituted by re-encoding the decoded
structure, not by string replacement, so a placeholder cannot corrupt the document or
accidentally match a substring elsewhere in the body.
