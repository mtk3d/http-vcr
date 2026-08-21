<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HttpVcr\Bridge\Guzzle\VcrMiddleware;
use HttpVcr\Config;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\RecordMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The middleware exists because Guzzle has a second, older API that never touches
 * sendRequest() — so a decorator sees nothing of it (§3.9). These tests go through that
 * API on purpose.
 */
#[CoversClass(VcrMiddleware::class)]
final class GuzzleMiddlewareTest extends TestCase
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

    public function testRecordsACallMadeThroughGuzzlesOwnApiWhichTheDecoratorNeverSees(): void
    {
        $handler = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"title":"T-Shirt"}')]);
        $guzzle = $this->guzzle($this->vcr(), $handler);

        $response = $guzzle->get('https://shop.example.com/products/1.json');

        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
        self::assertSame(0, $handler->count(), 'the queued response was not consumed');
        self::assertSame(1, $this->cassettes->cassette('shopify/get-product.json')->count());
    }

    public function testReplaysWithoutReachingTheHandlerAtAll(): void
    {
        $this->record();

        // Nothing queued: reaching the handler is a failure, not an empty response.
        $handler = new MockHandler([]);
        $guzzle = $this->guzzle($this->vcr(RecordMode::PlaybackOnly), $handler);

        $response = $guzzle->get('https://shop.example.com/products/1.json');

        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
    }

    public function testCoversTheOtherEntryPointsIntoTheSameStack(): void
    {
        $this->record();

        $vcr = new VcrClient(
            null,
            'shopify/get-product',
            RecordMode::PlaybackOnly,
            repeatablePlayback: true,
            persister: $this->cassettes->persister(),
        );
        $guzzle = $this->guzzle($vcr, new MockHandler([]));

        $viaNativeApi = $guzzle->get('https://shop.example.com/products/1.json');
        $viaPsr18 = $guzzle->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
        $viaAsync = $guzzle->requestAsync('GET', 'https://shop.example.com/products/1.json')->wait();

        self::assertSame('{"title":"T-Shirt"}', (string) $viaNativeApi->getBody());

        self::assertSame('{"title":"T-Shirt"}', (string) $viaPsr18->getBody());
        self::assertInstanceOf(ResponseInterface::class, $viaAsync);
        self::assertSame('{"title":"T-Shirt"}', (string) $viaAsync->getBody());
    }

    public function testTheRealRequestTravelsThroughTheRestOfTheStackRatherThanAroundIt(): void
    {
        $seen = [];

        // Pushed after the VCR layer, so it sits below it — closer to the transport — and
        // sees the real request only, never one served from the cassette.
        $tap = Middleware::mapRequest(static function (RequestInterface $request) use (&$seen): RequestInterface {
            $seen[] = (string) $request->getUri();

            return $request;
        });

        $recording = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $recording->push(VcrMiddleware::create($this->vcr()));
        $recording->push($tap);

        (new Client(['handler' => $recording]))->get('https://shop.example.com/products/1.json');

        $replaying = HandlerStack::create(new MockHandler([]));
        $replaying->push(VcrMiddleware::create($this->vcr(RecordMode::PlaybackOnly)));
        $replaying->push($tap);

        (new Client(['handler' => $replaying]))->get('https://shop.example.com/products/1.json');

        self::assertSame(['https://shop.example.com/products/1.json'], $seen);
    }

    public function testTheSessionSurvivesFromOneRequestToTheNext(): void
    {
        $handler = new MockHandler([new Response(200, [], '{"page":1}'), new Response(200, [], '{"page":2}')]);
        $guzzle = $this->guzzle($this->vcr(), $handler);

        $guzzle->get('https://shop.example.com/products?page=1');
        $guzzle->get('https://shop.example.com/products?page=2');

        $cassette = $this->cassettes->cassette('shopify/get-product.json');

        self::assertSame(2, $cassette->count());
        self::assertSame(['{"page":1}', '{"page":2}'], $cassette->responseBodies());
    }

    public function testAFailureComesBackAsARejectedPromiseRatherThanEscapingTheAsyncCall(): void
    {
        $this->record();

        $guzzle = $this->guzzle($this->vcr(RecordMode::PlaybackOnly), new MockHandler([]));

        // The call itself comes back with a promise rather than throwing on the way out:
        // requestAsync() promises a promise, whatever went wrong underneath.
        $promise = $guzzle->requestAsync('GET', 'https://shop.example.com/orders');

        self::assertSame('rejected', $promise->getState());

        $this->expectException(NoMatchingInteractionException::class);

        $promise->wait();
    }

    public function testATransportFailureFromTheHandlerReachesTheCallerUnchanged(): void
    {
        $request = new Request('GET', 'https://shop.example.com/products/1.json');
        $handler = new MockHandler([new ConnectException('Connection refused', $request)]);
        $guzzle = $this->guzzle($this->vcr(), $handler);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection refused');

        $guzzle->get('https://shop.example.com/products/1.json');
    }

    private function record(): void
    {
        $handler = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"title":"T-Shirt"}')]);

        $this->guzzle($this->vcr(), $handler)->get('https://shop.example.com/products/1.json');
    }

    private function vcr(RecordMode $mode = RecordMode::RecordIfAbsent): VcrClient
    {
        // No inner client: the middleware supplies one per request, wrapping whatever
        // handler comes next in the stack.
        return new VcrClient(null, 'shopify/get-product', $mode, persister: $this->cassettes->persister());
    }

    private function guzzle(VcrClient $vcr, MockHandler $handler): Client
    {
        $stack = HandlerStack::create($handler);
        $stack->push(VcrMiddleware::create($vcr));

        return new Client(['handler' => $stack]);
    }
}
