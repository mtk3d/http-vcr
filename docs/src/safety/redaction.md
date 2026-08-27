# Redacting Sensitive Data

Recording real API traffic means real credentials pass through http-vcr on their way to disk.

## What happens without any configuration

Four headers are redacted automatically, from the first recording, with nothing to set up: `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`. They almost always carry a credential and almost never carry anything a test asserts on, so the cost of redacting them by default is close to zero — and the cost of not doing it is a token in git history, which is permanent.

```json
"authorization": ["<REDACTED-AUTHORIZATION>"]
```

Anything else — a key in a query string, a secret in a form body, an email in a response you'd rather not commit — is opt-in, and the rest of this page is about it. Which is exactly why there's also a second safety net that needs no configuration either:

## The automatic check after recording

Every session that records something runs the newly recorded interactions through a credential heuristic (`Bearer ` tokens, AWS-style keys, long token-shaped strings, auth headers that don't look like placeholders) and warns about what it finds:

```
http-vcr: recorded 1 interaction → tests/Cassettes/shopify/get-product.yaml
  response.body carries a credential-shaped value, stored unredacted:
    "sk_live_…" (32 chars)
```

On a terminal the cassette, the location and the value are colored, so the finding can be picked out of a long run without reading it line by line — [`NO_COLOR` and `FORCE_COLOR`](../reference/environment.md#color) decide that. It reports what it found and where it sits. What to do about it is a judgement it can't make for you: `sk_live_…` in a payment test is a real leak, the same string in a fixture describing an error response is not. Redact and record again, or leave it as it is.

It never fails a test and never blocks the write — the cassette is on disk either way, and the point is to put the finding in front of you while the context is still fresh, before the file is committed. Only interactions recorded *in that session* are checked, so a finding you've looked at and accepted doesn't come back every run.

For the blocking version — every cassette, with an exit code CI can act on — run [`vendor/bin/http-vcr scan-secrets`](../reference/cli.md#scan-secrets). To turn the automatic check off, set `scanRecordingsForSecrets: false` in [`http-vcr.php`](../reference/configuration.md); there's deliberately no environment variable for it, since silencing a secrets warning should be a decision visible in review rather than something appended to one command.

```php
$vcr->redact('<SHOPIFY_API_KEY>', fn () => $_ENV['SHOPIFY_API_KEY']);
```

Redaction is symmetric: it applies to both the request **and** the response, at write time and again, in reverse, at replay time.

## How it works

Two things happen, at two different moments:

- **When recording**: the real value is replaced with the placeholder before anything touches disk.
- **When replaying**: the placeholder is swapped back for the real value — twice. Once on the *recorded request*, before it's compared against the incoming request (otherwise a matcher would compare a placeholder against a real value and never match). And once on the *recorded response*, before it's handed back to application code — otherwise the code would receive the placeholder string instead of the real token it expects to work with. This second half only applies to rules that were given a way to produce the real value — see [One-way vs. two-way](#one-way-vs-two-way).

Redaction covers everything stored in an interaction, including the `errorMessage` of a [recorded transport failure](../advanced/transport-errors.md) — HTTP client exception messages routinely quote the full request URL, query string and all.

## Helpers for common cases

```php
$vcr->redactHeader('X-Api-Key');
$vcr->redactJsonField('/customer/email');
$vcr->redactQueryParam('api_key');       // ?api_key=xxx in the URL itself
$vcr->redactFormField('client_secret');  // application/x-www-form-urlencoded body
```

`redact()` takes the placeholder as its first argument, but these four are only given a field name, so they generate one: `<REDACTED-{NAME}>`, with the name upper-cased and anything outside `[A-Z0-9]` turned into a dash. `redactHeader('X-Api-Key')` writes `<REDACTED-X-API-KEY>`, `redactJsonField('/customer/email')` writes `<REDACTED-CUSTOMER-EMAIL>`. The value is fixed rather than random, so a cassette diff stays readable and [`scan-secrets`](../reference/cli.md#scan-secrets) recognizes it as a placeholder from its shape alone.

All redaction methods, like every other configuration call on `VcrClient`, have to run before the first request of the cassette session — see [VcrClient Reference](../reference/vcr-client.md#configuration-is-frozen-after-the-first-request).

## One-way vs. two-way

"Symmetric" above describes two different axes, and only one of them is unconditional.

**Request and response** — always both. Every helper covers both halves of an interaction, wherever the field makes sense on both sides.

**Record and replay** — only when http-vcr knows the real value, which means only when you hand it one:

```php
$vcr->redact('<API_KEY>', fn () => $_ENV['API_KEY']);          // two-way
$vcr->redactHeader('X-Api-Key', fn () => $_ENV['API_KEY']);    // two-way
$vcr->redactHeader('X-Api-Key');                               // one-way: write only
```

A value provider is called when the value is needed rather than when the rule is declared, so it can read a credential a framework's `.env` puts in place later in the boot. If `$_ENV` reads as empty in your suite, see [where http-vcr looks for your variables](../reference/environment.md#your-own-variables).

A one-way rule replaces the value with a placeholder on the way to disk and has nothing to restore it from on the way back. Two consequences, both of which look like bugs if you don't expect them:

1. **The field stops distinguishing interactions.** Comparing a placeholder against a real value would never match, so http-vcr redacts the *incoming* request the same way before matching, leaving the same placeholder on both sides (see [Matching Requests](../concepts/matching.md#redacted-values-are-normalized-on-both-sides)). Two recordings that differ only in a one-way redacted field become indistinguishable.
2. **Application code receives the placeholder** at replay time. That's fine for a field the test only asserts on (`customer.email`), and not fine for a token the application reads out of the response and sends in its next request (`refresh_token`). For anything in that second category, pass a value provider.

### What restoring matches on

A two-way rule puts the real value back where it finds **its own placeholder** — not wherever the field it names appears. The two field-targeted cases differ:

- `redactHeader`, `redactJsonField`, `redactQueryParam`, `redactFormField` restore a field whose recorded value is *exactly* the placeholder. A cassette holding a real email under `/customer/email` — recorded before the rule existed, or under a placeholder that has since been renamed — replays that email unchanged.
- `redact()` works on the text, so it swaps the placeholder back wherever it occurs, including in the middle of a body or an [error message](../advanced/transport-errors.md).

The practical consequence is that adding a rule doesn't reach back into cassettes recorded without it. Re-record the cassette so the placeholder is actually in the file:

```bash
VCR_ERASE_TAPE=shopify/get-product vendor/bin/phpunit --filter GetProduct
```

## Opting out of the default header redaction

The four headers redacted by default are covered [at the top of this page](#what-happens-without-any-configuration).

That default redaction is one-way — the library never knew the real value — so those four headers stop being a distinguishing factor for matching, like any other one-way rule. To match on one of them, opt it out of redaction:

```php
// opt-out, for a test that specifically verifies the auth header itself
$vcr->includeSensitiveHeaders(['Authorization']);
```

This doesn't replace `vendor/bin/http-vcr scan-secrets` — that command scans for secrets *outside* this default set (custom headers, tokens embedded in a body or query string). The default redaction only covers the one case common enough not to require any setup at all.

## Project-wide redaction

`redact()` on a `VcrClient` instance covers that instance's cassette. For a secret common to *every* cassette in the project — a company-wide proxy token, say, not something tied to one specific provider — declaring it once in `http-vcr.php` avoids repeating the same call everywhere:

```php
return HttpVcr\Config::create(
    // ...
    redact: ['<COMPANY_PROXY_TOKEN>' => fn () => $_ENV['COMPANY_PROXY_TOKEN']],
);
```

This is deliberately flat — it doesn't key by [`provider`](../integrations/phpunit.md#providers), even though providers are a core concept the config already knows about. A `Provider` carries two things (hosts and `requiresEnv`), that's enough for everything else built on it, and the case for adding a third has a trivial workaround below; it's on the [roadmap](../reference/roadmap.md), not ruled out. For a secret specific to one provider (a `SHOPIFY_API_KEY` used across many Shopify tests), the auto-redacted `Authorization` header usually already covers it; where it doesn't (the secret shows up in a body or query string instead), the recommended pattern is a small base test case in your own project rather than a new library feature:

```php
abstract class ShopifyTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->vcrClient()->redact('<SHOPIFY_API_KEY>', fn () => $_ENV['SHOPIFY_API_KEY']);
    }
}
```

`setUp()` runs after the PHPUnit extension has already constructed and injected `VcrClient` for the test, so it's still unfrozen at that point — see [PHPUnit Integration](../integrations/phpunit.md) for the exact lifecycle.

## Recipe: redacting IP addresses

Not a separate mechanism — the same helpers as above, applied to wherever an IP address actually shows up.

For a value that varies at runtime (a response's `last_login_ip`, a request's `X-Forwarded-For`), the field-targeted helpers apply, same as any other field — the placeholder they generate follows the same readable `<REDACTED-...>` convention as the built-in header redaction, not a real-looking address:

```php
$vcr->redactHeader('X-Forwarded-For');
$vcr->redactJsonField('/user/last_login_ip');
```

For a fixed, known value you want out — your own static outbound IP, say, appearing literally in a body or header — `redact()` lets you pick the placeholder yourself. That's the place to reach for an address from [RFC 5737](https://www.rfc-editor.org/rfc/rfc5737) (`192.0.2.1`, `198.51.100.1`, `203.0.113.1`) instead of a random-looking one: those ranges are permanently reserved for documentation and never route on the real internet, so the placeholder can't be mistaken for an address that happens to still work.

```php
$vcr->redact('203.0.113.1', fn () => $_ENV['MY_SERVER_IP']);
```

Not enabled by default: unlike the four auth-related headers above, IP addresses aren't a near-universal credential-shaped risk in every cassette, so there's no default pattern redacted automatically — only what's declared explicitly, same as any other `redact*()` call.
