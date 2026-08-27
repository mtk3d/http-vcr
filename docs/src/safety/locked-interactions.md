# Locked Interactions

Auto re-record (`staleAfter`, forced recording via `VCR_ERASE_TAPE`) assumes refreshing a recording is safe. For a `GET`, that's usually true. For a mutating request — creating an order, charging a payment, deleting a resource, anything with a real side effect or a one-time token that can't be replayed — it usually isn't. Locking protects exactly that case.

It's the same idea as the physical write-protect tab on a VHS tape or cassette: `VCR_ERASE_TAPE` erases the tape, but not the part of it that's protected.

## Locking one interaction

```bash
vendor/bin/http-vcr lock shopify/checkout --interaction=2
```

This sets `locked: true` on interaction #2 in `shopify/checkout`. The same thing can be done by hand, directly in the cassette file — the CLI command exists for convenience, not because it's the only way in.

Once locked, that interaction **never** generates a real HTTP request again, no matter what:

- Under forced recording, it's excluded from the cassette truncation and keeps being replayed from what's already recorded, while everything else in the cassette refreshes normally.
- `VCR_ERASE_TAPE` — whether it names this cassette directly, is set to `all`, or narrows to a single provider — and `VCR_ALLOW_RECORDING=1` don't override it. Locking sits above both in precedence — see the [Environment Variables](../reference/environment.md) reference for the full table.

That's also why forced recording still runs the matchers even after emptying a cassette: it has to recognize that an incoming request is aimed at a locked interaction before it can leave that interaction alone. Locked survivors keep their relative order at the front of the file, and freshly recorded interactions are appended after them — relevant if the cassette is also under [`StrictMode::InOrder`](../advanced/strict-mode.md), which will expect that order on the next run.

```bash
# refreshes every Shopify interaction, wherever it lives... except the locked ones
SHOPIFY_API_KEY=xxx VCR_ERASE_TAPE=@shopify vendor/bin/phpunit
```

## Unlocking

Always explicit, never automatic:

```bash
vendor/bin/http-vcr unlock shopify/checkout --interaction=2
```

— or delete the `"locked": true` line from the JSON by hand.

There's no signature or checksum protecting the `locked` field itself. That's deliberate, and consistent with the rest of the cassette format being plain, hand-editable JSON: the real protection is that a change to `locked` shows up as a visible line in a pull request diff, the same way any other cassette change does.

## Locking a whole cassette from the test itself

For a cassette that's *entirely* about a sensitive operation, locking every interaction one by one is unnecessary ceremony. Declare it once, in the test:

```php
#[UseCassette('shopify/checkout', locked: true)]
public function testCheckoutCreatesOrder(): void { /* ... */ }
```

This has the same effect as locking every interaction in the file, but with two things the data-only version doesn't give: it's visible right in the test code without opening the cassette, and it still holds even if the `locked` field in the JSON gets accidentally reverted — the code-level lock takes precedence over what's in the data.

Use the JSON field for surgical, per-interaction locking, when a cassette mixes safe-to-refresh reads with one sensitive write. Use the attribute when the whole cassette is sensitive.

A fully locked cassette plus `VCR_ERASE_TAPE` is a no-op by construction: nothing is erased, nothing is requested, and the file is unchanged when the run finishes. The run says so explicitly (`cassette fully locked, VCR_ERASE_TAPE had no effect`) rather than leaving it looking like the variable was ignored.
