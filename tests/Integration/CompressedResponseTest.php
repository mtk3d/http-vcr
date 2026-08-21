<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * A gzipped response is stored decompressed: redaction has nothing to say about a gzip
 * frame, and neither does anyone reading the cassette in a pull request.
 */
#[CoversClass(VcrClient::class)]
#[RequiresPhpExtension('zlib')]
final class CompressedResponseTest extends TestCase
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

    public function testAGzippedResponseIsStoredAsReadableText(): void
    {
        $this->client($this->gzipped('{"title":"T-Shirt"}'))->sendRequest($this->request());

        $cassette = $this->cassettes->cassette('api/compressed.json');
        self::assertSame('{"title":"T-Shirt"}', $cassette->responseBody(0));
        self::assertSame('', $cassette->bodyEncoding(0), 'text, not bytes — so no base64 either');
        self::assertSame([], $cassette->responseHeader(0, 'Content-Encoding'));
    }

    public function testTheRecordingRunSeesTheSameResponseTheReplayingRunWill(): void
    {
        $recorded = $this->client($this->gzipped('{"title":"T-Shirt"}'))->sendRequest($this->request());
        $replayed = $this->client(new FakeHttpClient())->sendRequest($this->request());

        self::assertSame('{"title":"T-Shirt"}', (string) $recorded->getBody());
        self::assertSame('{"title":"T-Shirt"}', (string) $replayed->getBody());
        self::assertFalse($recorded->hasHeader('Content-Encoding'));
        self::assertFalse($replayed->hasHeader('Content-Encoding'));
    }

    public function testContentLengthIsCorrectedRatherThanLeftDescribingTheCompressedBytes(): void
    {
        $body = '{"title":"T-Shirt"}';
        $compressed = (string) gzencode($body);
        $inner = (new FakeHttpClient())->willRespond(new Response(200, [
            'Content-Encoding' => 'gzip',
            'Content-Length' => (string) strlen($compressed),
        ], $compressed));

        $response = $this->client($inner)->sendRequest($this->request());

        self::assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    public function testDeflateIsAcceptedInBothSpellingsFoundInTheWild(): void
    {
        $zlibWrapped = (new FakeHttpClient())->willRespond(
            new Response(200, ['Content-Encoding' => 'deflate'], (string) gzcompress('zlib-wrapped')),
        );
        $raw = (new FakeHttpClient())->willRespond(
            new Response(200, ['Content-Encoding' => 'deflate'], (string) gzdeflate('raw')),
        );

        self::assertSame('zlib-wrapped', (string) $this->client($zlibWrapped)->sendRequest($this->request())->getBody());
        self::assertSame('raw', (string) $this->client($raw, 'api/raw-deflate')->sendRequest($this->request())->getBody());
    }

    public function testCompressionItselfCanBeWhatIsUnderTest(): void
    {
        $compressed = (string) gzencode('{"title":"T-Shirt"}');
        $inner = (new FakeHttpClient())->willRespond(new Response(200, ['Content-Encoding' => 'gzip'], $compressed));

        $vcr = new VcrClient(
            $inner,
            'api/compressed',
            decodeCompressedResponse: false,
            persister: $this->cassettes->persister(),
        );
        $response = $vcr->sendRequest($this->request());

        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $cassette = $this->cassettes->cassette('api/compressed.json');
        self::assertSame(['gzip'], $cassette->responseHeader(0, 'Content-Encoding'));
        self::assertSame('base64', $cassette->bodyEncoding(0), 'compressed bytes are bytes');
    }

    public function testAnEncodingThisBuildCannotDecompressIsStoredExactlyAsItArrived(): void
    {
        $inner = (new FakeHttpClient())->willRespond(
            new Response(200, ['Content-Encoding' => 'exotic-v9'], 'whatever that is'),
        );

        $response = $this->client($inner)->sendRequest($this->request());

        self::assertSame('exotic-v9', $response->getHeaderLine('Content-Encoding'));
        self::assertSame(
            ['exotic-v9'],
            $this->cassettes->cassette('api/compressed.json')->responseHeader(0, 'Content-Encoding'),
        );
    }

    private function gzipped(string $body): FakeHttpClient
    {
        return (new FakeHttpClient())->willRespond(
            new Response(200, ['Content-Encoding' => 'gzip', 'Content-Type' => 'application/json'], (string) gzencode($body)),
        );
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.com/products');
    }

    private function client(FakeHttpClient $inner, string $cassette = 'api/compressed'): VcrClient
    {
        return new VcrClient($inner, $cassette, persister: $this->cassettes->persister());
    }
}
