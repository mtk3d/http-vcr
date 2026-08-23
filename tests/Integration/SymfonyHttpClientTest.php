<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Bridge\Symfony\VcrHttpClient;
use HttpVcr\Config;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bridge is for the interface a Symfony application autowires — not `Psr18Client`,
 * which the core takes as it is (§3.10).
 */
#[CoversClass(VcrHttpClient::class)]
final class SymfonyHttpClientTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testItIsTheInterfaceSymfonyServicesAskFor(): void
    {
        self::assertInstanceOf(HttpClientInterface::class, new VcrHttpClient($this->vcr(null)));
    }

    public function testRecordsACallMadeThroughSymfonysOwnSignature(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');
        $client = new VcrHttpClient($this->vcr($inner));

        $response = $client->request('GET', 'https://shop.example.com/products/1.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"title":"T-Shirt"}', $response->getContent());
        self::assertSame(['title' => 'T-Shirt'], $response->toArray());
        self::assertSame(1, $this->cassettes->cassette('shopify/get-product.json')->count());
    }

    public function testReplaysWithStatusHeadersAndTransferInfoInPlace(): void
    {
        $this->record();

        $client = new VcrHttpClient($this->vcr(null, RecordMode::PlaybackOnly));

        $response = $client->request('GET', 'https://shop.example.com/products/1.json');

        self::assertSame('{"title":"T-Shirt"}', $response->getContent());
        self::assertSame(['application/json'], $response->getHeaders()['content-type']);
        self::assertSame(200, $response->getInfo('http_code'));
        self::assertSame('https://shop.example.com/products/1.json', $response->getInfo('url'));
    }

    public function testAnErrorStatusBehavesTheWayASymfonyResponseDoes(): void
    {
        $inner = (new FakeHttpClient)->willRespond(new Response(404, [], '{"error":"gone"}'));
        $client = new VcrHttpClient($this->vcr($inner));

        $response = $client->request('GET', 'https://shop.example.com/products/9.json');

        self::assertSame(404, $response->getStatusCode());

        $this->expectException(ClientExceptionInterface::class);

        $response->getContent();
    }

    public function testTheOptionsSymfonyExpandsReachTheCassetteExpanded(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"ok":true}');
        $client = new VcrHttpClient($this->vcr($inner));

        $client->request('POST', 'https://shop.example.com/products', [
            'json' => ['title' => 'T-Shirt'],
            'query' => ['draft' => 1],
            'auth_bearer' => 'shpat_secret',
        ]);

        $cassette = $this->cassettes->cassette('shopify/get-product.json');

        self::assertSame('https://shop.example.com/products?draft=1', $cassette->requestUri(0));
        self::assertSame('{"title":"T-Shirt"}', $cassette->requestBody(0));
        self::assertSame(['application/json'], $cassette->requestHeader(0, 'Content-Type'));

        // The four sensitive headers are redacted on the way to disk, which is exactly what
        // auth_bearer becomes by the time the bridge sees it (§3.4).
        self::assertSame(['<REDACTED-AUTHORIZATION>'], $cassette->requestHeader(0, 'Authorization'));
    }

    public function testWithOptionsMergesDefaultsIntoANewInstance(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"ok":true}');
        $client = new VcrHttpClient($this->vcr($inner));

        $configured = $client->withOptions([
            'base_uri' => 'https://shop.example.com/admin/',
            'headers' => ['X-Shop-Domain' => 'shop.example.com'],
        ]);

        self::assertNotSame($client, $configured);

        $configured->request('GET', 'products.json');

        $cassette = $this->cassettes->cassette('shopify/get-product.json');

        self::assertSame('https://shop.example.com/admin/products.json', $cassette->requestUri(0));
        self::assertSame(['shop.example.com'], $cassette->requestHeader(0, 'X-Shop-Domain'));
    }

    public function testStreamingAReplayedResponseYieldsItsChunks(): void
    {
        $this->record();

        $client = new VcrHttpClient($this->vcr(null, RecordMode::PlaybackOnly));
        $response = $client->request('GET', 'https://shop.example.com/products/1.json');

        $content = '';

        foreach ($client->stream($response) as $chunk) {
            $content .= $chunk->getContent();
        }

        self::assertSame('{"title":"T-Shirt"}', $content);
    }

    private function record(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');

        (new VcrHttpClient($this->vcr($inner)))->request('GET', 'https://shop.example.com/products/1.json');
    }

    private function vcr(?FakeHttpClient $inner, RecordMode $mode = RecordMode::RecordIfAbsent): VcrClient
    {
        return new VcrClient($inner, 'shopify/get-product', $mode, persister: $this->cassettes->persister());
    }
}
