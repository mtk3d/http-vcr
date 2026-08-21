<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\EraseTape;
use HttpVcr\Exception\RecordingNotAllowedException;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Provider;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * VCR_ERASE_TAPE end to end: a cassette is truncated when the session opens, down to what
 * the selector spares, and everything else is requested for real and recorded fresh.
 */
#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
#[CoversClass(EraseTape::class)]
final class ForcedRecordingTest extends TestCase
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

    public function testTheNamedCassetteIsRecordedFromScratch(): void
    {
        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/products/1', '{"title":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = 'sync/order-flow';

        $inner = (new FakeHttpClient())->willRespond('{"title":"new"}');
        $vcr = new VcrClient($inner, 'sync/order-flow', persister: $this->persister());
        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));
        $vcr->close();

        self::assertSame('{"title":"new"}', (string) $response->getBody());
        $cassette = $this->cassettes->cassette('sync/order-flow.json');
        self::assertSame(1, $cassette->count());
        self::assertSame('{"title":"new"}', $cassette->responseBody(0));
    }

    public function testACassetteTheSelectorDoesNotNameIsReplayedAsUsual(): void
    {
        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/products/1', '{"title":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = 'some/other-cassette';

        $inner = new FakeHttpClient();
        $response = (new VcrClient($inner, 'sync/order-flow', persister: $this->persister()))
            ->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));

        self::assertSame(0, $inner->sentCount());
        self::assertSame('{"title":"old"}', (string) $response->getBody());
    }

    public function testALockedInteractionIsSparedAndKeepsBeingReplayed(): void
    {
        $this->seed('shopify/checkout', [
            $this->interaction('https://shop.example.com/orders', '{"id":"original"}', locked: true),
            $this->interaction('https://shop.example.com/products/1', '{"title":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = 'all';

        $inner = (new FakeHttpClient())->willRespond('{"title":"new"}');
        $vcr = new VcrClient($inner, 'shopify/checkout', persister: $this->persister());

        $order = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/orders'));
        $product = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));
        $vcr->close();

        self::assertSame('{"id":"original"}', (string) $order->getBody());
        self::assertSame('{"title":"new"}', (string) $product->getBody());
        self::assertSame(1, $inner->sentCount(), 'only the unlocked interaction went to the real API');

        $cassette = $this->cassettes->cassette('shopify/checkout.json');
        self::assertSame('{"id":"original"}', $cassette->responseBody(0));
        self::assertTrue($cassette->isLocked(0));
        self::assertSame('{"title":"new"}', $cassette->responseBody(1));
    }

    public function testNarrowingToOneApiLeavesTheOtherApiInTheSameCassetteAlone(): void
    {
        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/orders/1', '{"order":"old"}'),
            $this->interaction('https://acme.zendesk.com/tickets', '{"ticket":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = '@shop.example.com';

        $inner = (new FakeHttpClient())->willRespond('{"order":"new"}');
        $vcr = new VcrClient($inner, 'sync/order-flow', persister: $this->persister());

        $ticket = $vcr->sendRequest(new Request('GET', 'https://acme.zendesk.com/tickets'));
        $order = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/orders/1'));
        $vcr->close();

        self::assertSame('{"ticket":"old"}', (string) $ticket->getBody());
        self::assertSame('{"order":"new"}', (string) $order->getBody());
        self::assertSame(1, $inner->sentCount());
    }

    public function testTheNameAProjectGaveAnApiSelectsEveryHostItCovers(): void
    {
        VcrClient::configure(providers: [
            'shopify' => new Provider(hosts: ['*.example.com'], requiresEnv: []),
        ]);

        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/orders/1', '{"order":"old"}'),
            $this->interaction('https://acme.zendesk.com/tickets', '{"ticket":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = '@shopify';

        $inner = (new FakeHttpClient())->willRespond('{"order":"new"}');
        $vcr = new VcrClient($inner, 'sync/order-flow', persister: $this->persister());

        $ticket = $vcr->sendRequest(new Request('GET', 'https://acme.zendesk.com/tickets'));
        $order = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/orders/1'));
        $vcr->close();

        self::assertSame('{"ticket":"old"}', (string) $ticket->getBody());
        self::assertSame('{"order":"new"}', (string) $order->getBody());
    }

    public function testSurvivorsKeepTheirOrderAtTheFrontAndFreshRecordingsFollow(): void
    {
        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/orders/1', '{"order":"old"}'),
            $this->interaction('https://acme.zendesk.com/tickets', '{"ticket":"old"}'),
        ]);
        $_ENV['VCR_ERASE_TAPE'] = '@shop.example.com';

        $inner = (new FakeHttpClient())->willRespond('{"order":"new"}');
        $vcr = new VcrClient($inner, 'sync/order-flow', persister: $this->persister());
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/orders/1'));
        $vcr->sendRequest(new Request('GET', 'https://acme.zendesk.com/tickets'));
        $vcr->close();

        self::assertSame(
            ['{"ticket":"old"}', '{"order":"new"}'],
            $this->cassettes->cassette('sync/order-flow.json')->responseBodies(),
        );
    }

    public function testAFullyLockedCassetteIsLeftExactlyAsItWas(): void
    {
        $this->seed('shopify/checkout', [
            $this->interaction('https://shop.example.com/orders', '{"id":"original"}', locked: true),
        ]);
        $before = $this->cassettes->read('shopify/checkout.json');
        $_ENV['VCR_ERASE_TAPE'] = 'all';

        $inner = new FakeHttpClient();
        $vcr = new VcrClient($inner, 'shopify/checkout', persister: $this->persister());
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/orders'));
        $vcr->close();

        self::assertSame(0, $inner->sentCount());
        self::assertSame($before, $this->cassettes->read('shopify/checkout.json'));
    }

    public function testWithRecordingDisabledTheCassetteIsLeftAloneRatherThanErased(): void
    {
        $this->seed('sync/order-flow', [
            $this->interaction('https://shop.example.com/products/1', '{"title":"old"}'),
        ]);
        $before = $this->cassettes->read('sync/order-flow.json');
        $_ENV['VCR_ALLOW_RECORDING'] = '0';
        $_ENV['VCR_ERASE_TAPE'] = 'sync/order-flow';

        $vcr = new VcrClient(new FakeHttpClient(), 'sync/order-flow', persister: $this->persister());

        try {
            $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1'));
            self::fail('expected the run to refuse rather than erase what it cannot record again');
        } catch (RecordingNotAllowedException $exception) {
            self::assertStringContainsString(
                'Recording is disabled by VCR_ALLOW_RECORDING=0, ignoring VCR_ERASE_TAPE',
                $exception->getMessage(),
            );
        }

        self::assertSame($before, $this->cassettes->read('sync/order-flow.json'));
    }

    /**
     * @param list<array<string, mixed>> $interactions
     */
    private function seed(string $cassette, array $interactions): void
    {
        $this->cassettes->write($cassette . '.json', json_encode(
            ['schemaVersion' => 1, 'interactions' => $interactions],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function interaction(string $uri, string $body, bool $locked = false): array
    {
        return [
            'request' => ['method' => 'GET', 'uri' => $uri, 'headers' => [], 'body' => ''],
            'response' => ['status' => 200, 'headers' => [], 'body' => $body],
            'outcome' => 'success',
            'recordedAt' => '2026-08-01T10:00:00+00:00',
            'locked' => $locked,
        ];
    }

    private function persister(): FilesystemCassettePersister
    {
        return $this->cassettes->persister();
    }
}
