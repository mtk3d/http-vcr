<?php

declare(strict_types=1);

/**
 * The PHPUnit integration end to end (§3.12).
 *
 * Two things have to be in place before an attribute means anything:
 *
 * 1. the extension registered in `phpunit.xml`, which is what reads the attribute:
 *
 *    ```xml
 *    <extensions>
 *        <bootstrap class="HttpVcr\Bridge\PHPUnit\Extension"/>
 *    </extensions>
 *    ```
 *
 * 2. a PSR-18 client to record through, either detected among the installed ones or named
 *    in `http-vcr.php` as `innerClientFactory`. A replaying test never touches it.
 *
 * Without the extension entry nothing looks at `#[UseCassette]`, so `vcrClient()` refuses
 * rather than handing back a client with no cassette behind it.
 */

use GuzzleHttp\Psr7\Request;
use HttpVcr\Bridge\PHPUnit\CassetteDirectory;
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;
use PHPUnit\Framework\TestCase;

/**
 * A module that keeps its cassettes beside itself rather than in the project-wide
 * `tests/Cassettes`. Declared once on the base class: PHP does not carry attributes down to
 * a subclass, so the bridge walks up the chain to find it, and `__DIR__` resolves against
 * the file it is written in.
 *
 * Keep such a base class under `testDirectories`, or the CLI's parser never sees it.
 */
#[CassetteDirectory(__DIR__.'/Cassettes')]
abstract class BillingTestCase extends TestCase
{
    use InteractsWithCassettes;
}

final class ChargeTest extends BillingTestCase
{
    /**
     * The common case: name a cassette, then use the client. First run records, every run
     * after replays.
     */
    #[UseCassette('billing/charge')]
    public function testItChargesTheCard(): void
    {
        $response = $this->vcrClient()->sendRequest(
            new Request('POST', 'https://api.stripe.com/v1/charges'),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Every parameter here is a VcrClient parameter under the same name — the attribute
     * says which of them this test wants, and adds nothing of its own.
     *
     * - `requiresEnv` is checked when a request is about to be recorded for real, not at
     *   the start of the test, so replaying needs no secrets at all.
     * - `staleAfter` is reported by `vendor/bin/http-vcr stale`; on its own it does not
     *   fail the run (`VCR_ENFORCE_STALE_CHECK=1` makes it).
     * - `locked` protects the recording from `VCR_ERASE_TAPE` — for a test whose requests
     *   are not safe to replay against the real API.
     */
    #[UseCassette(
        'billing/refund',
        strictMode: StrictMode::AllPlayed,
        staleAfter: new DateInterval('P7D'),
        requiresEnv: ['STRIPE_API_KEY'],
        locked: true,
    )]
    public function testItRefundsTheCharge(): void
    {
        // ...
    }

    /**
     * A test that must never reach the network, whatever the environment says: a missing
     * cassette is then a failed test rather than a real request.
     */
    #[UseCassette('billing/dispute', RecordMode::PlaybackOnly)]
    public function testItReadsADispute(): void
    {
        // ...
    }
}

/**
 * On the class, `#[UseCassette]` is shorthand for the same attribute on every test method
 * in it — one file, but a separate session per method, not a shared one. A method-level
 * attribute replaces it outright rather than merging with it, the way PHPUnit's own
 * attributes behave.
 */
#[UseCassette('billing/webhooks')]
final class WebhookTest extends BillingTestCase
{
    public function testItAcceptsAWebhook(): void
    {
        // replays from billing/webhooks
    }

    #[UseCassette('billing/webhooks-retry')]
    public function testItRetriesARejectedWebhook(): void
    {
        // replays from billing/webhooks-retry
    }
}

/**
 * Re-recording one API without touching the rest, and without needing a test filter — the
 * selector decides what is erased, so an unfiltered run gives the same result, just slower:
 *
 * ```bash
 * STRIPE_API_KEY=sk_test_… VCR_ERASE_TAPE=@api.stripe.com vendor/bin/phpunit
 * ```
 *
 * When the suite is too slow for that, the filter is computed rather than guessed:
 *
 * ```bash
 * vendor/bin/phpunit --filter "$(vendor/bin/http-vcr tests --provider=api.stripe.com --filter-only)"
 * ```
 */
