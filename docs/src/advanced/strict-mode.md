# Strict & Sequential Mode

By default, http-vcr doesn't care whether every recorded interaction actually got replayed, or in what order. `StrictMode` turns that into an assertion.

## `StrictMode::AllPlayed`

Fails when the cassette closes if any recorded interaction was never replayed:

```php
new VcrClient($inner, cassette: 'shopify/get-product', strictMode: StrictMode::AllPlayed);
```

This catches drift in the opposite direction from a missing match: instead of "the code asked for something the cassette doesn't have," it's "the cassette has something the code never asked for" — usually a sign a code path got removed, or a cassette was recorded once and never trimmed down.

A [`repeatablePlayback`](../concepts/record-modes.md) interaction never gets consumed, so "unplayed" means something slightly different for it: it counts as played once it's been replayed **at least once**. One that nothing ever asked for still fails `AllPlayed` — that's precisely the signal this mode is for.

With [scoped cassettes](scoping.md), this is checked **per scope file**, not aggregated across a whole test run. If a test touches both a `2024-01` and a `2024-04` scope, each of those two physical files has to independently close with zero unplayed interactions — treating them as one shared pool would hide which specific file has the leftover.

## `StrictMode::InOrder`

Fails unless interactions are replayed in exactly the order they were recorded:

```php
new VcrClient($inner, cassette: 'shopify/checkout', strictMode: StrictMode::InOrder);
```

This is for code where the *sequence* of calls matters, not just which calls happen — a checkout flow that has to create a cart before it can add an item to it, for example.

[`repeatablePlayback`](../concepts/record-modes.md) interactions are exempt from the ordering check: only the order of *non-repeatable* interactions relative to each other counts. A repeatable interaction — typically the target of retry logic — can be replayed multiple times, anywhere in the sequence, without breaking `InOrder` for everything else.

## In a session that records

Both modes assert on how the *existing* recording got replayed, so a session that recorded anything — `ExtendCassette` picking up a new request, `RecordIfAbsent` creating the cassette, a forced re-record — checks only the interactions that were in the cassette when the session opened, ignoring the ones it added along the way. Otherwise `AllPlayed` would pass trivially after every recording run (a just-recorded interaction was, by definition, "played") and `InOrder` would be comparing a sequence against a list it built itself in the same run.

Under forced recording, "in the cassette when the session opened" means *after* the truncation — truncation is part of opening, not something that happens afterwards. So `VCR_ERASE_TAPE=<cassette>` on a cassette with no locks leaves both modes with an empty set to check (they pass trivially), while whatever the selector spared is checked exactly as usual: [locked interactions](../safety/locked-interactions.md), and — when the selector named a [provider](../integrations/phpunit.md#providers) — the other providers' traffic that kept replaying. That's the intent — there's nothing to assert about a recording the same run just erased.

One wrinkle specific to `InOrder` and a *partial* re-record: survivors are written back at the front of the file and freshly recorded interactions appended after them, so refreshing one provider inside a multi-API cassette reorders it relative to the sequence the code under test actually performs. If such a cassette is under `InOrder`, look at the file after a partial refresh — or re-record the whole thing (`VCR_ERASE_TAPE=<cassette>`, no `@provider`), which restores the natural execution order.

## When the check runs

Both modes are checked by `VcrClient::close()`, which also releases the recording lock. The [PHPUnit integration](../integrations/phpunit.md) calls it in its after-test hook; a hand-built client is closed by whatever built it:

```php
$vcr = new VcrClient($inner, cassette: 'shopify/checkout', strictMode: StrictMode::AllPlayed);
// ... exercise the code under test ...
$vcr->close();
```

The destructor releases the lock too, but never raises a strict-mode failure: it runs at a moment nothing chose — often while another exception is already on its way up, where an assertion would bury the actual failure.

## Setting it per test, not just globally

Both examples above configure `strictMode` on the `VcrClient` constructor directly, which applies for as long as that instance lives. With the [PHPUnit attribute](../integrations/phpunit.md), the same thing is set per test:

```php
#[UseCassette('shopify/checkout', strictMode: StrictMode::InOrder)]
public function testCheckoutCreatesOrderThenCapturesPayment(): void { /* ... */ }
```

That's usually the better default than turning it on globally: `AllPlayed`/`InOrder` tend to matter for one specific, well-understood action, not the whole suite — a blanket `AllPlayed` would just produce false alarms on every unrelated cassette that happens to carry an unused interaction from an earlier refactor.
