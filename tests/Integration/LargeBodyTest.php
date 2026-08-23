<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Exception\CassetteIntegrityException;
use HttpVcr\Persistence\SidecarBodies;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A body past the inline threshold goes to a file of its own — base64 inside JSON costs a
 * third more than the bytes and json_encode holds another copy, which for a downloaded file
 * is how a test suite runs out of memory.
 */
#[CoversClass(SidecarBodies::class)]
#[CoversClass(VcrClient::class)]
final class LargeBodyTest extends TestCase
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

    public function testABodyPastTheThresholdLeavesAReferenceAndAFileOfItsOwn(): void
    {
        $payload = str_repeat('A', 200);

        $this->client($this->responding($payload))->sendRequest($this->request());

        $cassette = $this->cassettes->cassette('api/download.json');
        $sha256 = hash('sha256', $payload);

        self::assertSame(substr($sha256, 0, 16), $cassette->bodyRef(0));
        self::assertSame($sha256, $cassette->bodySha256(0));
        self::assertSame('', $cassette->rawResponseBody(0), 'the body itself is not in the file');
        self::assertSame($payload, $this->cassettes->read('api/download.'.substr($sha256, 0, 16).'.bin'));
    }

    public function testItComesBackByteForByteOnReplay(): void
    {
        $payload = random_bytes(4096);
        $this->client($this->responding($payload))->sendRequest($this->request());

        $replayed = $this->client(new FakeHttpClient)->sendRequest($this->request());

        self::assertSame($payload, (string) $replayed->getBody());
    }

    public function testABodyUnderTheThresholdStaysInTheCassette(): void
    {
        $this->client($this->responding('{"small":true}'))->sendRequest($this->request());

        $cassette = $this->cassettes->cassette('api/download.json');
        self::assertSame('', $cassette->bodyRef(0));
        self::assertSame('{"small":true}', base64_decode($cassette->rawResponseBody(0), true));
        self::assertSame([], glob($this->cassettes->path.'/api/*.bin') ?: []);
    }

    public function testTwoInteractionsWithTheSameBodyShareOneFile(): void
    {
        $payload = str_repeat('A', 200);
        $inner = (new FakeHttpClient)
            ->willRespond(new Response(200, ['Content-Type' => 'application/octet-stream'], $payload))
            ->willRespond(new Response(200, ['Content-Type' => 'application/octet-stream'], $payload));

        $vcr = $this->client($inner);
        $vcr->sendRequest($this->request());
        $vcr->sendRequest($this->request());
        $vcr->close();

        self::assertCount(1, glob($this->cassettes->path.'/api/*.bin') ?: []);
    }

    public function testASidecarNothingReferencesAnyMoreIsRemovedWhenTheCassetteIsWritten(): void
    {
        $this->client($this->responding(str_repeat('A', 200)))->sendRequest($this->request());
        self::assertCount(1, glob($this->cassettes->path.'/api/*.bin') ?: []);

        $_ENV['VCR_ERASE_TAPE'] = 'api/download';
        $vcr = $this->client($this->responding(str_repeat('B', 200)));
        $vcr->sendRequest($this->request());
        $vcr->close();

        $files = glob($this->cassettes->path.'/api/*.bin') ?: [];
        self::assertCount(1, $files, 'the erased recording took its body file with it');
        self::assertSame(str_repeat('B', 200), (string) file_get_contents($files[0]));
    }

    public function testAnEditedBodyFileIsRefusedRatherThanReplayedAsWrongBytes(): void
    {
        $payload = str_repeat('A', 200);
        $this->client($this->responding($payload))->sendRequest($this->request());

        $sidecar = (glob($this->cassettes->path.'/api/*.bin') ?: [])[0];
        file_put_contents($sidecar, str_repeat('B', 200));

        $this->expectException(CassetteIntegrityException::class);
        $this->expectExceptionMessage('no longer matches its recorded bodySha256');

        $this->client(new FakeHttpClient)->sendRequest($this->request());
    }

    public function testAMissingBodyFileSaysWhichFileIsGone(): void
    {
        $this->client($this->responding(str_repeat('A', 200)))->sendRequest($this->request());
        unlink((glob($this->cassettes->path.'/api/*.bin') ?: [])[0]);

        $this->expectException(CassetteIntegrityException::class);
        $this->expectExceptionMessage('which is not there');

        $this->client(new FakeHttpClient)->sendRequest($this->request());
    }

    public function testBodyFilesAreNotMistakenForCassettes(): void
    {
        $this->client($this->responding(str_repeat('A', 200)))->sendRequest($this->request());

        self::assertSame(
            ['api/download'],
            iterator_to_array($this->cassettes->persister()->list('json'), false),
        );
    }

    private function responding(string $payload): FakeHttpClient
    {
        return (new FakeHttpClient)->willRespond(
            new Response(200, ['Content-Type' => 'application/octet-stream'], $payload),
        );
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.com/export');
    }

    private function client(FakeHttpClient $inner): VcrClient
    {
        return new VcrClient(
            $inner,
            'api/download',
            inlineBodyLimit: 100,
            persister: $this->cassettes->persister(),
        );
    }
}
