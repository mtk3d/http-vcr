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

        // The repository's own http-vcr.php has the report off, since most of this suite
        // replays a subset on purpose. This file is where it is the subject.
        Config::reset();
        VcrClient::configure(reportUnplayedInteractions: true);
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

    /**
     * The default: nothing fails, but a cassette holding interactions the run never asked
     * for is the sign of a recording that has drifted from the code, and that is worth
     * saying out loud without anyone opting in.
     */
    public function testWithoutAnyStrictModeTheInteractionsNothingAskedForAreReported(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::None);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->close();

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('1 of 2 recorded interactions was never replayed', $this->warnings[0]);
        self::assertStringContainsString('#2  POST https://shop.example.com/orders', $this->warnings[0]);
    }

    public function testACassetteThatWasFullyReplayedSaysNothing(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::None);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));
        $vcr->close();

        self::assertSame([], $this->warnings);
    }

    /**
     * A session that recorded the interactions itself has replayed none of them, and is
     * the normal first run — judging it would mean warning about every new cassette.
     */
    public function testWhatTheSessionRecordedItselfIsNotReportedEither(): void
    {
        $inner = (new FakeHttpClient)->willRespond('{"cart":1}')->willRespond('{"order":1}');

        $vcr = $this->client('shopify/checkout', StrictMode::None, $inner);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->sendRequest(new Request('POST', 'https://shop.example.com/orders'));
        $vcr->close();

        self::assertSame([], $this->warnings);
    }

    public function testACassetteNoRequestEverReachedIsLeftAlone(): void
    {
        $this->recordTwo();

        $this->client('shopify/checkout', StrictMode::None)->close();

        self::assertSame([], $this->warnings, 'a test that never used its client is not a drifted cassette');
    }

    public function testTheReportCanBeTurnedOff(): void
    {
        $this->recordTwo();

        // reset() first because recordTwo() already built a client, and configuration is
        // frozen from the first one — the reset is what the library's own suite has instead
        // of a fresh process per test.
        Config::reset();
        VcrClient::configure(reportUnplayedInteractions: false);

        $vcr = $this->client('shopify/checkout', StrictMode::None);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->close();

        self::assertSame([], $this->warnings);
    }

    /**
     * AllPlayed already fails on exactly this, and InOrder deliberately says nothing about
     * what was never asked for — so the report is what fills that gap.
     */
    public function testTheStrictModeThatFailsOnThisDoesNotAlsoWarnAboutIt(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::AllPlayed);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));

        try {
            $vcr->close();
        } catch (StrictModeViolationException) {
        }

        self::assertSame([], $this->warnings);
    }

    public function testInOrderStillGetsTheReport(): void
    {
        $this->recordTwo();

        $vcr = $this->client('shopify/checkout', StrictMode::InOrder);
        $vcr->sendRequest(new Request('GET', 'https://shop.example.com/cart'));
        $vcr->close();

        self::assertCount(1, $this->warnings);
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
            // Collected rather than left to standard error: every session here replays a
            // subset on purpose, which is exactly what the report is about.
            warn: $this->collect(...),
        );
    }

    /** @var list<string> */
    private array $warnings = [];

    private function collect(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
