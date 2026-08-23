# ADR-0001: Decorate a PSR-18 client

**Status:** Accepted · **Reference:** `PLAN.md` §1, §2

## Context

A record/replay library has to get between the code under test and the network. There are
three well-trodden ways to do that in PHP:

1. **Patch the transport.** Register a `stream_wrapper`, or swap `curl_*` out from under the
   process. This is what several established VCR ports do. It catches every request in the
   process, including ones from code you do not control.
2. **Swap the client implementation.** Ship a fake HTTP client the test injects instead of
   the real one.
3. **Decorate the client.** Wrap whatever client the project already uses in an object with
   the same interface.

Option 1 catches the most traffic, and pays for it with process-wide state: something has to
be installed before the first request and torn down after the last, the teardown has to
survive a failing test, and two tests that both want it cannot run in the same process with
different settings. Option 2 forces the project to build for testability in a specific
shape, and gives up on anything that constructs its own client internally — which most
vendor SDKs do.

## Decision

`VcrClient` implements `Psr\Http\Client\ClientInterface` and takes the real client as a
constructor argument. Nothing else is installed, patched, or registered.

## Consequences

**Good.** Two `VcrClient` instances in one process never interfere — there is no shared
state for them to interfere through. Nothing needs resetting between tests, and a test that
fails halfway leaves no global mess behind. Anything already speaking PSR-18 works
unchanged: Guzzle 7+, Symfony's `Psr18Client`, php-http, Buzz. The decorator is also
trivially inspectable — it is one object, and you can see it in the constructor call.

**Bad.** Code that builds its own client internally and offers no seam is out of reach.
This is why the [bridges](../../integrations/guzzle.md) exist: Guzzle's `HandlerStack` and
Symfony's `HttpClientInterface` are the two places where SDKs commonly do accept an
injected piece, and each gets an adapter that reaches the same core.

**Also bad, and accepted.** Retries, redirects and connection reuse happen *below* the
decorator, inside the real client. A cassette records what the client returned, not what
the wire carried. A test that needs to assert on redirect behaviour is testing the client,
not your code, and http-vcr is the wrong tool for it.
