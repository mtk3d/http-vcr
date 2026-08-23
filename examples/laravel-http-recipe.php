<?php

declare(strict_types=1);

/**
 * Recording the Laravel `Http` facade without adding a dependency (§3.11).
 *
 * The zero-ceremony route is the separate `mtk3d/laravel-http-vcr` package, which does all
 * of this from a service provider. This file is the same interception written out by hand,
 * for a project that would rather not install anything else — it is a recipe to copy, not
 * part of the library, and nothing autoloads it.
 *
 * Under the facade is Guzzle with a configurable handler, so the Guzzle middleware (§3.9)
 * is all that is needed. `Http::globalOptions()` — Laravel 11 and newer — applies the
 * handler to every request the facade makes, whichever method the calling code used.
 *
 * `Http::withOptions()` is not the same thing and does not work here: it returns a
 * PendingRequest configured for one call, so on its own it configures an object that is
 * then thrown away.
 */

use GuzzleHttp\HandlerStack;
use HttpVcr\Bridge\Guzzle\VcrMiddleware;
use HttpVcr\Bridge\PHPUnit\CurrentCassetteSession;
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Put this in the base test case of the suite. Every test then behaves as it always did,
 * except that one carrying #[UseCassette] — for which every `Http::` call is recorded and
 * replayed.
 */
abstract class RecordsHttpFacadeCalls extends TestCase
{
    use InteractsWithCassettes;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside setUp(), not before it: the application — and with it the Http factory
        // this configures — is built by parent::setUp() and thrown away after the test.
        if (! CurrentCassetteSession::isActive()) {
            return;
        }

        $stack = HandlerStack::create();
        $stack->push(VcrMiddleware::create($this->vcrClient()));

        Http::globalOptions(['handler' => $stack]);
    }
}

/**
 * The test itself says nothing about handlers.
 */
final class GetProductTest extends RecordsHttpFacadeCalls
{
    #[UseCassette('shopify/get-product', requiresEnv: ['SHOPIFY_API_KEY'])]
    public function testItReadsTheProduct(): void
    {
        $product = Http::get('https://shop.myshopify.com/admin/api/2024-01/products/123.json')->json();

        $this->assertSame('T-Shirt', $product['title']);
    }
}
