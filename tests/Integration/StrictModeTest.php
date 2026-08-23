<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\StrictModeViolationException;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
#[CoversClass(StrictModeViolationException::class)]
final class StrictModeTest extends TestCase
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

    public function testAllPlayedPassesWhenEveryInteractionWasReplayed(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::AllPlayed);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));

        $vcr->close();

        $this->expectNotToPerformAssertions();
    }

    public function testAllPlayedNamesTheInteractionsNothingAskedFor(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::AllPlayed);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        $this->expectException(StrictModeViolationException::class);
        $this->expectExceptionMessage('StrictMode::AllPlayed: 1 of 2 recorded interactions');
        $this->expectExceptionMessage('#2  POST https://shop.example.com/orders');

        $vcr->close();
    }

    public function testAllPlayedFailsForACassetteTheTestNeverTouched(): void
    {
        $this->recordTwo();

        $this->expectException(StrictModeViolationException::class);
        $this->expectExceptionMessage('2 of 2 recorded interactions');

        $this->client('shopify/checkout', StrictMode::AllPlayed)->close();
    }

    public function testARepeatableInteractionCountsAsPlayedOnceItHasBeenReplayedAtAll(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::AllPlayed, repeatablePlayback: true);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        $this->expectException(StrictModeViolationException::class);
        $this->expectExceptionMessage('#2  POST https://shop.example.com/orders');

        $vcr->close();
    }

    public function testInOrderPassesWhenTheSequenceMatchesTheRecording(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::InOrder);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));

        $vcr->close();

        $this->expectNotToPerformAssertions();
    }

    public function testInOrderNamesThePairThatCameOutBackwards(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::InOrder);
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        $this->expectException(StrictModeViolationException::class);
        $this->expectExceptionMessage('StrictMode::InOrder');
        $this->expectExceptionMessage('#1  GET https://shop.example.com/cart  came after');
        $this->expectExceptionMessage('#2  POST https://shop.example.com/orders');

        $vcr->close();
    }

    public function testInOrderIgnoresWhatIsMissingAltogether(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::InOrder);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        $vcr->close();

        $this->expectNotToPerformAssertions();
    }

    public function testARepeatableInteractionIsExemptFromTheOrdering(): void
    {
        $this->recordTwo();

        // The retry loop reaches for the same GET again, after the POST that follows it in
        // the cassette — which is exactly what repeatablePlayback is for.
        $vcr = $this->client('shopify/checkout', StrictMode::InOrder, repeatablePlayback: true);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        $vcr->close();

        $this->expectNotToPerformAssertions();
    }

    public function testWhatTheSessionRecordedItselfIsNotJudged(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"cart":1}')->willRespond('{"order":1}');

        $vcr = $this->client('shopify/checkout', StrictMode::AllPlayed, $inner);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));

        $vcr->close();

        self::assertSame(2, $this->cassettes->cassette('shopify/checkout.json')->count());
    }

    public function testTheLockIsGivenBackEvenWhenTheAssertionFails(): void
    {
        $this->recordTwo();

        $vcr = $this->client(
            'shopify/checkout',
            StrictMode::AllPlayed,
            mode: RecordMode::ExtendCassette,
        );
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        try {
            $vcr->close();
        } catch (StrictModeViolationException) {
        }

        self::assertTrue($this->lockIsFree('shopify/checkout'));
    }

    private function lockIsFree(string $cassette): bool
    {
        $handle = fopen($this->cassettes->path.'/.http-vcr/'.$cassette.'.cassette-lock', 'c');
        self::assertIsResource($handle);

        $free = flock($handle, LOCK_EX | LOCK_NB);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $free;
    }

    private function recordTwo(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"cart":1}')->willRespond('{"order":1}');
        $vcr = new VcrClient($inner, 'shopify/checkout', persister: $this->cassettes->persister());

        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));
        $vcr->close();
    }

    private function client(
        string $cassette,
        StrictMode $strictMode,
        ?FakeHttpClient $inner = null,
        bool $repeatablePlayback = false,
        RecordMode $mode = RecordMode::RecordIfAbsent,
    ): VcrClient {
        return new VcrClient(
            $inner ?? new FakeHttpClient,
            $cassette,
            $mode,
            strictMode: $strictMode,
            repeatablePlayback: $repeatablePlayback,
            persister: $this->cassettes->persister(),
        );
    }
}
