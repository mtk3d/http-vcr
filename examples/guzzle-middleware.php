<?php

declare(strict_types=1);

/**
 * Recording a Guzzle client that uses Guzzle's own API (§3.9).
 *
 * `GuzzleHttp\Client` is a PSR-18 client, so `new VcrClient($guzzle, 'name')` already
 * records anything sent through `sendRequest()`. What it does not cover is Guzzle's own
 * surface — `get()`, `post()`, `request()`, `requestAsync()`, `Pool` — which goes straight
 * to the handler stack, past any decorator around the client. A test using those methods
 * would make real requests with a cassette open and nothing said about it.
 *
 * The middleware sits in the stack instead, below every one of those entry points.
 *
 * Position in the stack is a choice, not a detail. Guzzle applies the stack from the
 * bottom up: whatever is pushed *after* this sits between the cassette and the network and
 * only ever sees real requests, and whatever was pushed *before* — including everything
 * `HandlerStack::create()` comes with, redirects and `http_errors` among them — treats a
 * replayed response exactly like one off the wire. So retries pushed after the middleware
 * do not re-run against a cassette, and retries pushed before it do.
 */

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use HttpVcr\Bridge\Guzzle\VcrMiddleware;
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use PHPUnit\Framework\TestCase;

final class ShopifyCheckoutTest extends TestCase
{
    use InteractsWithCassettes;

    /** @var list<array<string, mixed>> */
    private array $history = [];

    #[UseCassette('shopify/checkout', requiresEnv: ['SHOPIFY_API_KEY'])]
    public function testItReadsTheCartThroughGuzzlesOwnApi(): void
    {
        $cart = $this->client()->get('/admin/api/2024-01/cart.json');

        $this->assertSame(200, $cart->getStatusCode());
    }

    private function client(): Client
    {
        $stack = HandlerStack::create();

        // Pushed before the cassette: this logger sees replayed responses too.
        $stack->push(Middleware::history($this->history));

        $stack->push(VcrMiddleware::create($this->vcrClient()));

        return new Client([
            'handler' => $stack,
            'base_uri' => 'https://shop.myshopify.com',
            'headers' => ['Authorization' => 'Bearer ' . ($_ENV['SHOPIFY_API_KEY'] ?? '')],
        ]);
    }
}

/**
 * Outside PHPUnit — a script, a different test framework — the same middleware takes a
 * hand-built VcrClient. Nothing about the bridge needs the PHPUnit integration:
 *
 * ```php
 * $vcr = new VcrClient(null, 'shopify/checkout', RecordMode::RecordIfAbsent);
 *
 * $stack = HandlerStack::create();
 * $stack->push(VcrMiddleware::create($vcr));
 *
 * $response = (new Client(['handler' => $stack]))->get('https://shop.myshopify.com/cart');
 *
 * $vcr->close();   // writes the recording and releases the session lock
 * ```
 *
 * The `null` transport is deliberate: the middleware hands VcrClient the rest of the stack
 * as the client to record through, so a real request goes down the remaining middleware
 * rather than around it.
 */
