<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use DateInterval;
use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Clock\FrozenClock;
use HttpVcr\Config;
use HttpVcr\Exception\StaleCassetteException;
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
#[CoversClass(StaleCassetteException::class)]
final class StaleCassetteTest extends TestCase
{
    use ControlsEnvironment;

    private const RECORDED_AT = '2026-08-01T12:00:00+00:00';

    private const LONG_AFTER = '2026-08-21T12:00:00+00:00';

    private CassetteDirectory $cassettes;

    protected function setUp(): void
    {
        $this->cassettes = new CassetteDirectory();

        $this->takeOverEnvironment(
            'VCR_ALLOW_RECORDING',
            'VCR_ERASE_TAPE',
            'CI',
            'VCR_ENFORCE_STALE_CHECK',
            'VCR_IGNORE_STALE_CASSETTES',
        );
        $_ENV['VCR_ALLOW_RECORDING'] = '1';
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        $this->cassettes->remove();
        Config::reset();
    }

    public function testAStaleCassetteReplaysAsUsualWhenNothingAsksForEnforcement(): void
    {
        $this->recordAt(self::RECORDED_AT);

        $response = $this->client(self::LONG_AFTER)
            ->sendRequest(new Request('GET', 'https://api.example.com/products/1'));

        self::assertSame('{"id":1}', (string) $response->getBody());
    }

    public function testEnforcementTurnsTheSameCassetteIntoAFailureNamingTheInteraction(): void
    {
        $this->recordAt(self::RECORDED_AT);
        $_ENV['VCR_ENFORCE_STALE_CHECK'] = '1';

        $this->expectException(StaleCassetteException::class);
        $this->expectExceptionMessage('1 interaction in ' . $this->cassettes->path . '/shopify/get-product.json');
        $this->expectExceptionMessage('#1  GET https://api.example.com/products/1');
        $this->expectExceptionMessage('recorded 2026-08-01T12:00:00+00:00, stale since 2026-08-08T12:00:00+00:00');
        $this->expectExceptionMessage('VCR_ERASE_TAPE=shopify/get-product');

        $this->client(self::LONG_AFTER)->sendRequest(new Request('GET', 'https://api.example.com/products/1'));
    }

    public function testIgnoringStaleCassettesOutranksEnforcement(): void
    {
        $this->recordAt(self::RECORDED_AT);
        $_ENV['VCR_ENFORCE_STALE_CHECK'] = '1';
        $_ENV['VCR_IGNORE_STALE_CASSETTES'] = '1';

        $response = $this->client(self::LONG_AFTER)
            ->sendRequest(new Request('GET', 'https://api.example.com/products/1'));

        self::assertSame('{"id":1}', (string) $response->getBody());
    }

    public function testACassetteInsideTheThresholdPassesUnderEnforcement(): void
    {
        $this->recordAt(self::RECORDED_AT);
        $_ENV['VCR_ENFORCE_STALE_CHECK'] = '1';

        $response = $this->client('2026-08-05T12:00:00+00:00')
            ->sendRequest(new Request('GET', 'https://api.example.com/products/1'));

        self::assertSame('{"id":1}', (string) $response->getBody());
    }

    public function testOnlyTheInteractionThatOutlivedTheThresholdIsReported(): void
    {
        $this->recordAt(self::RECORDED_AT);
        $this->recordAt('2026-08-20T12:00:00+00:00', '/products/2', RecordMode::ExtendCassette);
        $_ENV['VCR_ENFORCE_STALE_CHECK'] = '1';

        try {
            $this->client(self::LONG_AFTER)->sendRequest(new Request('GET', 'https://api.example.com/products/1'));
            self::fail('Expected the stale cassette to be refused.');
        } catch (StaleCassetteException $exception) {
            self::assertStringContainsString('1 interaction in', $exception->getMessage());
            self::assertStringContainsString('#1  GET https://api.example.com/products/1', $exception->getMessage());
            self::assertStringNotContainsString('/products/2', $exception->getMessage());
        }
    }

    public function testEnforcementHasNothingToSayAboutACassetteBeingRecordedForTheFirstTime(): void
    {
        $_ENV['VCR_ENFORCE_STALE_CHECK'] = '1';

        $inner = (new FakeHttpClient())->willRespond('{"id":1}');
        $response = $this->client(self::RECORDED_AT, $inner)
            ->sendRequest(new Request('GET', 'https://api.example.com/products/1'));

        self::assertSame('{"id":1}', (string) $response->getBody());
    }

    private function recordAt(string $now, string $path = '/products/1', RecordMode $mode = RecordMode::RecordIfAbsent): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"id":' . substr($path, -1) . '}');

        $vcr = new VcrClient(
            $inner,
            'shopify/get-product',
            $mode,
            clock: FrozenClock::at($now),
            persister: $this->cassettes->persister(),
        );

        $vcr->sendRequest(new Request('GET', 'https://api.example.com' . $path));
        $vcr->close();
    }

    private function client(string $now, ?FakeHttpClient $inner = null): VcrClient
    {
        return new VcrClient(
            $inner ?? new FakeHttpClient(),
            'shopify/get-product',
            RecordMode::RecordIfAbsent,
            staleAfter: new DateInterval('P7D'),
            clock: FrozenClock::at($now),
            persister: $this->cassettes->persister(),
        );
    }
}
