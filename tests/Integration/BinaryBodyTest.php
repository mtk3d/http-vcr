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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JSON carries text, and not every body is text — a downloaded image has to survive a round
 * trip byte for byte.
 */
#[CoversClass(VcrClient::class)]
final class BinaryBodyTest extends TestCase
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

    public function testABinaryResponseIsStoredAsBase64AndReplayedByteForByte(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . random_bytes(64);
        $inner = (new FakeHttpClient())->willRespond(new Response(200, ['Content-Type' => 'image/png'], $png));

        $recorded = $this->client($inner)->sendRequest($this->request());

        self::assertSame($png, (string) $recorded->getBody());
        $cassette = $this->cassettes->cassette('api/download.json');
        self::assertSame('base64', $cassette->bodyEncoding(0));
        self::assertSame(base64_encode($png), $cassette->rawResponseBody(0));

        $replayed = $this->client(new FakeHttpClient())->sendRequest($this->request());
        self::assertSame($png, (string) $replayed->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textualContentTypes(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'json with charset' => ['application/json; charset=utf-8'];
        yield 'vendor json' => ['application/vnd.api+json'];
        yield 'plain text' => ['text/plain'];
        yield 'html' => ['text/html'];
        yield 'form' => ['application/x-www-form-urlencoded'];
    }

    #[DataProvider('textualContentTypes')]
    public function testTextStaysReadableInTheFile(string $contentType): void
    {
        $inner = (new FakeHttpClient())->willRespond(new Response(200, ['Content-Type' => $contentType], '{"title":"Łódź"}'));

        $this->client($inner)->sendRequest($this->request());

        $cassette = $this->cassettes->cassette('api/download.json');
        self::assertSame('', $cassette->bodyEncoding(0));
        self::assertSame('{"title":"Łódź"}', $cassette->responseBody(0));
    }

    public function testContentThatClaimsToBeTextButIsNotValidUtf8IsStoredAsBytesAnyway(): void
    {
        $latin1 = "caf\xE9";
        $inner = (new FakeHttpClient())->willRespond(new Response(200, ['Content-Type' => 'text/plain'], $latin1));

        $this->client($inner)->sendRequest($this->request());

        self::assertSame('base64', $this->cassettes->cassette('api/download.json')->bodyEncoding(0));
        self::assertSame($latin1, (string) $this->client(new FakeHttpClient())->sendRequest($this->request())->getBody());
    }

    public function testABinaryRequestBodyIsEncodedToo(): void
    {
        $bytes = random_bytes(32);
        $inner = (new FakeHttpClient())->willRespond('{"ok":true}');
        $request = new Request('POST', 'https://api.example.com/upload', ['Content-Type' => 'application/octet-stream'], $bytes);

        $this->client($inner)->sendRequest($request);

        $cassette = $this->cassettes->cassette('api/download.json');
        self::assertSame('base64', $cassette->requestBodyEncoding(0));
        self::assertSame($bytes, base64_decode($cassette->rawRequestBody(0), true));
    }

    public function testAnEmptyBodyIsNeverEncoded(): void
    {
        $inner = (new FakeHttpClient())->willRespond(new Response(204, ['Content-Type' => 'image/png'], ''));

        $this->client($inner)->sendRequest($this->request());

        self::assertSame('', $this->cassettes->cassette('api/download.json')->bodyEncoding(0));
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.com/logo.png');
    }

    private function client(FakeHttpClient $inner): VcrClient
    {
        return new VcrClient($inner, 'api/download', persister: $this->cassettes->persister());
    }
}
