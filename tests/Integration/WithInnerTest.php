<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Config;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\Tests\Support\InMemoryCassettePersister;
use HttpVcr\VcrClient;
use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * withInner() is what lets a middleware send the real request through the rest of its own
 * stack instead of around it (§3.9) — which only works if the instance it hands back is a
 * satellite of the same session rather than a second, independent one.
 */
#[CoversClass(VcrClient::class)]
final class WithInnerTest extends TestCase
{
    use ControlsEnvironment;

    private InMemoryCassettePersister $persister;

    protected function setUp(): void
    {
        $this->persister = new InMemoryCassettePersister();

        $this->takeOverEnvironment('VCR_ALLOW_RECORDING', 'VCR_ERASE_TAPE', 'CI');
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        Config::reset();
    }

    public function testTheRealRequestGoesToTheClientSuppliedPerRequest(): void
    {
        $constructed = new FakeHttpClient();
        $perRequest = (new FakeHttpClient())->willRespond('{"from":"the handler stack"}');
        $vcr = $this->client($constructed);

        $response = $vcr->withInner($perRequest)->sendRequest(new Request('GET', 'https://api.example.com/products'));

        self::assertSame('{"from":"the handler stack"}', (string) $response->getBody());
        self::assertSame(0, $constructed->sentCount());
        self::assertSame(1, $perRequest->sentCount());
    }

    public function testAClientBuiltWithoutAnInnerOneRecordsThroughTheClientWithInnerSupplies(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"id":1}');
        $vcr = $this->client(null);

        $vcr->withInner($inner)->sendRequest(new Request('GET', 'https://api.example.com/products'));

        self::assertSame(1, $inner->sentCount());
        self::assertTrue($this->persister->exists('api/products.json'));
    }

    public function testInteractionsStayConsumedAcrossTheInstancesAMiddlewareProduces(): void
    {
        $this->persister->write('api/products.json', <<<'JSON'
            {
                "schemaVersion": 1,
                "interactions": [
                    {
                        "request": {"method": "GET", "uri": "https://api.example.com/products", "headers": {}, "body": ""},
                        "response": {"status": 200, "headers": {}, "body": "{\"id\":1}"},
                        "outcome": "success",
                        "recordedAt": "2026-08-21T10:00:00+00:00"
                    }
                ]
            }
            JSON);

        $inner = new FakeHttpClient();
        $vcr = $this->client($inner);
        $request = new Request('GET', 'https://api.example.com/products');

        self::assertSame('{"id":1}', (string) $vcr->withInner($inner)->sendRequest($request)->getBody());

        // A second instance and a spent interaction: were the session per instance, this
        // would replay the recording all over again.
        $this->expectException(NoMatchingInteractionException::class);

        $vcr->withInner($inner)->sendRequest($request);
    }

    public function testConfigurationIsFrozenByARequestSentThroughASatelliteInstance(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"id":1}');
        $vcr = $this->client(null);

        $vcr->withInner($inner)->sendRequest(new Request('GET', 'https://api.example.com/products'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('redact() has to be called before the first request');

        $vcr->redact('<TOKEN>', static fn (): string => 'secret');
    }

    public function testASatelliteGoingOutOfScopeDoesNotEndTheSession(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"id":1}');
        $vcr = $this->client(null);

        $satellite = $vcr->withInner($inner);
        $satellite->sendRequest(new Request('GET', 'https://api.example.com/products'));
        unset($satellite);

        self::assertSame([], $this->persister->unlocked, 'the session lock was given back mid-run');

        $vcr->close();

        self::assertSame(['api/products.cassette-lock'], $this->persister->unlocked);
    }

    private function client(?FakeHttpClient $inner): VcrClient
    {
        return new VcrClient($inner, 'api/products', persister: $this->persister);
    }
}
