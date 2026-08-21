<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\Tests\Support\UnrewindableStream;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A PSR-7 body is a stream, and not every stream can be rewound — a multipart upload
 * reading from a file handle, a response being streamed as it arrives.
 */
#[CoversClass(VcrClient::class)]
final class StreamHandlingTest extends TestCase
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

    public function testARequestBodyThatCannotBeRewoundStillReachesTheRealClient(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"ok":true}');
        $request = (new Request('POST', 'https://api.example.com/upload'))
            ->withBody(new UnrewindableStream('{"amount":100}'));

        $this->client($inner)->sendRequest($request);

        self::assertSame('{"amount":100}', (string) $inner->sent[0]->getBody());
        self::assertSame('{"amount":100}', $this->cassettes->cassette('api/upload.json')->requestBody(0));
    }

    public function testAResponseBodyThatCannotBeRewoundIsStillReadableByTheCodeUnderTest(): void
    {
        $streamed = (new Response(200))->withBody(new UnrewindableStream('{"title":"T-Shirt"}'));
        $inner = (new FakeHttpClient())->willRespond($streamed);

        $response = $this->client($inner)->sendRequest(new Request('GET', 'https://api.example.com/upload'));

        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
        self::assertSame('{"title":"T-Shirt"}', $this->cassettes->cassette('api/upload.json')->responseBody(0));
    }

    public function testASeekableBodyIsHandedBackAsTheSameStreamRewound(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"ok":true}');
        $request = new Request('POST', 'https://api.example.com/upload', [], '{"amount":100}');
        $body = $request->getBody();

        $this->client($inner)->sendRequest($request);

        self::assertSame($body, $inner->sent[0]->getBody(), 'no substitution when none is needed');
        self::assertSame(0, $body->tell(), 'and the code under test finds it where it left it');
    }

    private function client(FakeHttpClient $inner): VcrClient
    {
        return new VcrClient($inner, 'api/upload', persister: $this->cassettes->persister());
    }
}
