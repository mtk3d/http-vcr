<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\VcrException;
use HttpVcr\Exception\VcrNetworkException;
use HttpVcr\Exception\VcrRequestException;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A transport failure — timeout, DNS, connection refused — is the case where PSR-18 has no
 * response to hand over at all, which is a different thing from a 4xx or 5xx.
 */
#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
#[CoversClass(VcrNetworkException::class)]
#[CoversClass(VcrRequestException::class)]
final class TransportErrorTest extends TestCase
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

    public function testByDefaultAFailureReachesTheCallerAndNothingIsWritten(): void
    {
        $failure = $this->networkFailure('cURL error 28: Operation timed out');
        $inner = (new FakeHttpClient())->willThrow($failure);

        try {
            $this->client($inner)->sendRequest($this->request());
            self::fail('the original exception should have reached the caller');
        } catch (ClientExceptionInterface $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertFalse($this->cassettes->has('api/flaky.json'));
    }

    public function testWithRecordTransportErrorsTheFailureIsStoredInPlaceOfAResponse(): void
    {
        $inner = (new FakeHttpClient())->willThrow($this->networkFailure('cURL error 28: Operation timed out'));

        try {
            $this->client($inner, recordTransportErrors: true)->sendRequest($this->request());
        } catch (ClientExceptionInterface) {
        }

        $cassette = $this->cassettes->cassette('api/flaky.json');
        self::assertSame('error', $cassette->outcome(0));
        self::assertSame('network', $cassette->errorCategory(0));
        self::assertSame('cURL error 28: Operation timed out', $cassette->errorMessage(0));
        self::assertStringContainsString('@anonymous', $cassette->errorClass(0));
        self::assertFalse($cassette->hasResponse(0));
    }

    public function testReplayingANetworkFailureThrowsThePsr18InterfaceTheApplicationCatches(): void
    {
        $this->recordFailure($this->networkFailure('cURL error 28: Operation timed out'));

        $request = $this->request();

        try {
            $this->client(new FakeHttpClient())->sendRequest($request);
            self::fail('the recorded failure should have been replayed');
        } catch (NetworkExceptionInterface $replayed) {
            self::assertInstanceOf(VcrNetworkException::class, $replayed);
            self::assertInstanceOf(VcrException::class, $replayed);
            self::assertSame($request, $replayed->getRequest());
            self::assertStringStartsWith('cURL error 28: Operation timed out', $replayed->getMessage());
            self::assertStringContainsString('replayed from a cassette', $replayed->getMessage());
        }
    }

    public function testARequestFailureReplaysAsTheOtherPsr18Interface(): void
    {
        $this->recordFailure($this->requestFailure('The request is missing a scheme'));

        $this->expectException(VcrRequestException::class);

        $this->client(new FakeHttpClient())->sendRequest($this->request());
    }

    public function testReplayNeverRebuildsTheOriginalClientsExceptionClass(): void
    {
        $this->recordFailure($this->networkFailure('cURL error 28: Operation timed out'));

        try {
            $this->client(new FakeHttpClient())->sendRequest($this->request());
            self::fail('the recorded failure should have been replayed');
        } catch (ClientExceptionInterface $replayed) {
            self::assertInstanceOf(VcrNetworkException::class, $replayed);
            // The original class survives as diagnostics in the message, nothing more.
            self::assertStringContainsString('recorded as', $replayed->getMessage());
        }
    }

    public function testAClientExceptionThatIsNeitherKindIsNotRecorded(): void
    {
        $inner = (new FakeHttpClient())->willThrow(
            new class ('something went sideways') extends RuntimeException implements ClientExceptionInterface {},
        );

        try {
            $this->client($inner, recordTransportErrors: true)->sendRequest($this->request());
        } catch (ClientExceptionInterface) {
        }

        self::assertFalse($this->cassettes->has('api/flaky.json'));
    }

    public function testAFailureIsConsumedOnReplayLikeAnyOtherInteraction(): void
    {
        $this->recordFailure($this->networkFailure('cURL error 28: Operation timed out'));

        $vcr = $this->client(new FakeHttpClient());

        try {
            $vcr->sendRequest($this->request());
        } catch (VcrNetworkException) {
        }

        $this->expectExceptionMessage('its one interaction was already consumed');

        $vcr->sendRequest($this->request());
    }

    private function recordFailure(ClientExceptionInterface $failure): void
    {
        $vcr = $this->client((new FakeHttpClient())->willThrow($failure), recordTransportErrors: true);

        try {
            $vcr->sendRequest($this->request());
        } catch (ClientExceptionInterface) {
        }

        $vcr->close();
    }

    private function networkFailure(string $message): ClientExceptionInterface&NetworkExceptionInterface
    {
        return new class ($message, $this->request()) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    private function requestFailure(string $message): ClientExceptionInterface&RequestExceptionInterface
    {
        return new class ($message, $this->request()) extends RuntimeException implements RequestExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.com/flaky');
    }

    private function client(FakeHttpClient $inner, bool $recordTransportErrors = false): VcrClient
    {
        return new VcrClient(
            $inner,
            'api/flaky',
            recordTransportErrors: $recordTransportErrors,
            persister: $this->cassettes->persister(),
        );
    }
}
