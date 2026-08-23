<?php

declare(strict_types=1);

/**
 * Recording a service that autowires Symfony's `HttpClientInterface` (§3.10).
 *
 * Two different things go by "Symfony HTTP client", and only one of them needs this
 * bridge:
 *
 * - `Symfony\Component\HttpClient\Psr18Client` is a PSR-18 client. Wrap it in a VcrClient
 *   like any other and nothing here applies.
 * - `Symfony\Contracts\HttpClient\HttpClientInterface` — the one a Symfony application
 *   injects into its services — has a signature of its own, a richer response type, and no
 *   `sendRequest()` to decorate. That is what `VcrHttpClient` implements.
 *
 * The service under test keeps asking for the interface it always asked for; the test
 * decides what is behind it.
 */

use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use HttpVcr\Bridge\Symfony\VcrHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProductCatalog
{
    public function __construct(private readonly HttpClientInterface $http) {}

    /**
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        return $this->http
            ->request('GET', 'https://shop.myshopify.com/admin/api/2024-01/products/'.$id.'.json', [
                'headers' => ['Accept' => 'application/json'],
            ])
            ->toArray();
    }
}

final class ProductCatalogTest extends TestCase
{
    use InteractsWithCassettes;

    #[UseCassette('shopify/get-product', requiresEnv: ['SHOPIFY_API_KEY'])]
    public function testItReadsAProduct(): void
    {
        $catalog = new ProductCatalog(new VcrHttpClient($this->vcrClient()));

        $this->assertSame('T-Shirt', $catalog->product(123)['title']);
    }
}

/**
 * In a functional test that boots the kernel, put the same client in the container
 * instead — the service under test is then reached through the container as usual:
 *
 * ```php
 * self::getContainer()->set(HttpClientInterface::class, new VcrHttpClient($this->vcrClient()));
 * ```
 *
 * `withOptions()` behaves as it does on any Symfony client: it returns a client carrying
 * those options as defaults, and the cassette is shared with the one it came from, since
 * both wrap the same VcrClient.
 */
