<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Config;
use HttpVcr\Hook\HookRegistry;
use HttpVcr\Matching\HeadersMatcher;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\RequestMatcherInterface;
use HttpVcr\Matching\UriMatcher;
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
#[CoversClass(HookRegistry::class)]
final class HooksTest extends TestCase
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

    public function testABeforeRecordHookChangesWhatLandsOnDisk(): void
    {
        $vcr = $this->client((new FakeHttpClient)->willRespond(
            new Response(200, ['X-Request-Id' => 'req-1'], '{"title":"T-Shirt"}'),
        ));
        $vcr->beforeRecord(static fn (Interaction $i): Interaction => $i->withResponse(
            $i->response?->withoutHeader('X-Request-Id') ?? throw new LogicException('No response.'),
        ));

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame([], $this->cassettes->cassette('shopify.json')->responseHeader(0, 'X-Request-Id'));
    }

    /**
     * Refusing an interaction isn't an error: the request really was made, so its response
     * goes back to the code under test either way. Only the cassette write is skipped.
     */
    public function testARefusedInteractionIsNotRecordedButIsStillAnswered(): void
    {
        $vcr = $this->client((new FakeHttpClient)->willRespond(new Response(503, [], 'upstream is down')));
        $vcr->beforeRecord(static fn (Interaction $i): ?Interaction => $i->response !== null && $i->response->status >= 500 ? null : $i);

        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('upstream is down', (string) $response->getBody());
        self::assertFalse($this->cassettes->has('shopify.json'));
    }

    /**
     * The hook runs before the matchers see anything, which is the whole point: a recorded
     * request changed here is the one matching compares against.
     */
    public function testABeforePlaybackHookChangesTheRequestMatchingSees(): void
    {
        $this->recordWithHeader('acme.myshopify.com');

        $vcr = $this->client(new FakeHttpClient, RecordMode::PlaybackOnly, [
            new MethodMatcher,
            new UriMatcher,
            new HeadersMatcher(['X-Shop-Domain']),
        ]);
        $vcr->beforePlayback(static fn (Interaction $i): Interaction => $i->withRequest(
            $i->request->withHeader('X-Shop-Domain', 'other.myshopify.com'),
        ));

        $response = $vcr->sendRequest(
            (new Request('GET', 'https://shop.example.com/products/1.json'))
                ->withHeader('X-Shop-Domain', 'other.myshopify.com'),
        );

        self::assertSame('{"title":"T-Shirt"}', (string) $response->getBody());
    }

    public function testABeforePlaybackHookChangesTheResponseTheCallerReceives(): void
    {
        $this->recordWithHeader('acme.myshopify.com');

        $vcr = $this->client(new FakeHttpClient, RecordMode::PlaybackOnly);
        $vcr->beforePlayback(static fn (Interaction $i): Interaction => $i->withResponse(
            $i->response?->withBody('{"title":"Replaced"}') ?? throw new LogicException('No response.'),
        ));

        $response = $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        self::assertSame('{"title":"Replaced"}', (string) $response->getBody());
    }

    public function testAPlaybackHookRefusingAnInteractionIsAProgrammingError(): void
    {
        $this->recordWithHeader('acme.myshopify.com');

        $vcr = $this->client(new FakeHttpClient, RecordMode::PlaybackOnly);
        /** @phpstan-ignore argument.type (a hook written wrong is exactly what this covers) */
        $vcr->beforePlayback(static fn (): ?Interaction => null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/beforePlayback hook returned null/');

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));
    }

    public function testAHookRegisteredAfterTheFirstRequestIsRefused(): void
    {
        $vcr = $this->client((new FakeHttpClient)->willRespond('{"title":"T-Shirt"}'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/products/1.json'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/beforeRecord\(\) has to be called before the first request/');

        $vcr->beforeRecord(static fn (Interaction $i): Interaction => $i);
    }

    private function recordWithHeader(string $domain): void
    {
        $this->client((new FakeHttpClient)->willRespond('{"title":"T-Shirt"}'))
            ->sendRequest(
                (new Request('GET', 'https://shop.example.com/products/1.json'))->withHeader('X-Shop-Domain', $domain),
            );
    }

    /**
     * @param  list<RequestMatcherInterface>  $matchers
     */
    private function client(
        FakeHttpClient $inner,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        array $matchers = [],
    ): VcrClient {
        return new VcrClient($inner, 'shopify', $mode, $matchers, persister: $this->cassettes->persister());
    }
}
