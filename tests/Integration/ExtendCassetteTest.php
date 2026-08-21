<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
final class ExtendCassetteTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory();

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testAnUnmatchedRequestIsAppendedInsteadOfThrowing(): void
    {
        $this->record('shopify/catalog', 'https://shop.example.com/products/1.json', '{"id":1}');

        $inner = (new FakeHttpClient())->willRespond('{"id":2}');
        $response = $this->client($inner, 'shopify/catalog')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/2.json'));

        self::assertSame('{"id":2}', (string) $response->getBody());
        self::assertSame(1, $inner->sentCount());

        $cassette = $this->cassettes->cassette('shopify/catalog.json');
        self::assertSame(2, $cassette->count());
        self::assertSame('https://shop.example.com/products/1.json', $cassette->requestUri(0));
        self::assertSame('https://shop.example.com/products/2.json', $cassette->requestUri(1));
    }

    public function testWhatIsAlreadyRecordedStillReplaysWithoutARealRequest(): void
    {
        $this->record('shopify/catalog', 'https://shop.example.com/products/1.json', '{"id":1}');

        $inner = new FakeHttpClient();
        $response = $this->client($inner, 'shopify/catalog')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame(0, $inner->sentCount());
        self::assertSame('{"id":1}', (string) $response->getBody());
        self::assertSame(1, $this->cassettes->cassette('shopify/catalog.json')->count());
    }

    public function testItRecordsFromScratchWhenThereIsNoCassetteYet(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"id":1}');

        $this->client($inner, 'shopify/catalog')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertTrue($this->cassettes->has('shopify/catalog.json'));
        self::assertSame(1, $this->cassettes->cassette('shopify/catalog.json')->count());
    }

    public function testWithRecordingDisabledItReplaysWhatItHasAndRefusesToAppend(): void
    {
        $this->record('shopify/catalog', 'https://shop.example.com/products/1.json', '{"id":1}');

        $_ENV['VCR_ALLOW_RECORDING'] = '0';

        $vcr = $this->client(new FakeHttpClient(), 'shopify/catalog');
        $replayed = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
        self::assertSame('{"id":1}', (string) $replayed->getBody());

        $this->expectException(RecordingNotAllowedException::class);
        $this->expectExceptionMessage('Recording is disabled by VCR_ALLOW_RECORDING=0');
        $this->expectExceptionMessage('GET https://shop.example.com/products/2.json');

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/2.json'));
    }

    private function record(string $cassette, string $uri, string $body): void
    {
        $this->client((new FakeHttpClient())->willRespond($body), $cassette, RecordMode::RecordIfAbsent)
            ->sendRequest(new Request('GET', $uri));
    }

    private function client(
        ?FakeHttpClient $inner,
        string $cassette,
        RecordMode $mode = RecordMode::ExtendCassette,
    ): VcrClient {
        return new VcrClient($inner, $cassette, $mode, persister: $this->cassettes->persister());
    }
}
