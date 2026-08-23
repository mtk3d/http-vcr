<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\CassetteNotFoundException;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
final class VcrClientTest extends TestCase
{
    use ControlsEnvironment;

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory;

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');

        // Whatever runs these tests, the library's own behaviour under CI detection is
        // decided per test rather than by the machine that happens to be running them.
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testRecordsARealRequestWhenThereIsNoCassetteYet(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"title":"T-Shirt"}');
        $vcr = $this->client($inner, 'shopify/get-product');

        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
        self::assertSame(1, $inner->sentCount());
        self::assertTrue($this->cassettes->has('shopify/get-product.json'));

        $cassette = $this->cassettes->cassette('shopify/get-product.json');
        self::assertSame(1, $cassette->schemaVersion());
        self::assertSame('https://shop.example.com/products/1.json', $cassette->requestUri(0));
        self::assertSame('{"title":"T-Shirt"}', $cassette->responseBody(0));
    }

    public function testReplaysAnExistingCassetteWithoutTouchingTheRealClient(): void
    {
        $this->record('shopify/get-product', 'https://shop.example.com/products/1.json', '{"title":"T-Shirt"}');

        $inner = new FakeHttpClient;
        $response = $this->client($inner, 'shopify/get-product')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame(0, $inner->sentCount());
        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
    }

    public function testAReplayedResponseCarriesTheRecordedStatusAndHeaders(): void
    {
        $inner = (new FakeHttpClient)->willRespond(
            new Response(201, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{"id":7}'),
        );
        $this->client($inner, 'orders/create')->sendRequest(new Request('POST', 'https://api.example.com/orders'));

        $response = $this->client(new FakeHttpClient, 'orders/create')
            ->sendRequest(new Request('POST', 'https://api.example.com/orders'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('abc', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('{"id":7}', (string) $response->getBody());
    }

    public function testTheRequestBodyIsRecordedAndLeftReadableForTheCodeUnderTest(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"ok":true}');
        $request = new Request('POST', 'https://api.example.com/orders', [], '{"amount":100}');

        $this->client($inner, 'orders/create')->sendRequest($request);

        self::assertSame('{"amount":100}', (string) $request->getBody());
        self::assertSame('{"amount":100}', $this->cassettes->cassette('orders/create.json')->requestBody(0));
    }

    public function testAnUnmatchedRequestAgainstAnExistingCassetteSaysWhyNothingMatched(): void
    {
        $this->record('shopify/get-product', 'https://shop.example.com/products/1.json', '{}');

        $this->expectException(NoMatchingInteractionException::class);
        $this->expectExceptionMessage('No matching interaction for GET https://shop.example.com/products/2.json');
        $this->expectExceptionMessage('1 unconsumed interaction:');
        $this->expectExceptionMessage('#1  UriMatcher: expected path "/products/1.json"');

        $this->client(new FakeHttpClient, 'shopify/get-product')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/2.json'));
    }

    public function testPlaybackOnlyWithNoCassetteFailsRatherThanRecording(): void
    {
        $this->expectException(CassetteNotFoundException::class);
        $this->expectExceptionMessage('No cassette at '.$this->cassettes->path.'/shopify/get-product.json');

        $this->client(new FakeHttpClient, 'shopify/get-product', RecordMode::PlaybackOnly)
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
    }

    public function testWithRecordingDisabledAMissingCassetteBlamesTheVariableRatherThanTheFile(): void
    {
        $_ENV['VCR_ALLOW_RECORDING'] = '0';

        $this->expectException(RecordingNotAllowedException::class);
        $this->expectExceptionMessage('Recording is disabled by VCR_ALLOW_RECORDING=0');
        $this->expectExceptionMessage('GET https://shop.example.com/products/1.json');

        $this->client(new FakeHttpClient, 'shopify/get-product')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
    }

    public function testDetectedCiBlocksRecordingAndNamesTheVariableThatCausedIt(): void
    {
        unset($_ENV['VCR_ALLOW_RECORDING']);
        $_ENV['CI'] = 'true';

        $this->expectException(RecordingNotAllowedException::class);
        $this->expectExceptionMessage('CI detection (CI=true is set, VCR_ALLOW_RECORDING is not)');

        $this->client(new FakeHttpClient, 'shopify/get-product')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
    }

    public function testNothingIsWrittenWhenRecordingIsRefused(): void
    {
        $_ENV['VCR_ALLOW_RECORDING'] = '0';

        try {
            $this->client(new FakeHttpClient, 'shopify/get-product')
                ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
        } catch (RecordingNotAllowedException) {
        }

        self::assertFalse(is_dir($this->cassettes->path));
    }

    public function testAnInnerlessClientCanReplayButSaysSoPlainlyWhenItWouldHaveToRecord(): void
    {
        $this->record('shopify/get-product', 'https://shop.example.com/products/1.json', '{"title":"T-Shirt"}');

        $replayed = $this->client(null, 'shopify/get-product')
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
        self::assertSame('{"title":"T-Shirt"}', (string) $replayed->getBody());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('built without an inner client');

        $this->client(null, 'shopify/other')->sendRequest(new Request('GET', 'https://shop.example.com/x'));
    }

    private function record(string $cassette, string $uri, string $body): void
    {
        $this->client((new FakeHttpClient)->willRespond($body), $cassette)
            ->sendRequest(new Request('GET', $uri));
    }

    private function client(
        ?FakeHttpClient $inner,
        string $cassette,
        RecordMode $mode = RecordMode::RecordIfAbsent,
    ): VcrClient {
        return new VcrClient(
            $inner,
            $cassette,
            $mode,
            persister: $this->cassettes->persister(),
        );
    }
}
