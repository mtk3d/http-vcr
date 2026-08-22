<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Bridge\PHPUnit\CassetteFactory;
use HttpVcr\Bridge\PHPUnit\CurrentCassetteSession;
use HttpVcr\Bridge\PHPUnit\Extension;
use HttpVcr\Bridge\PHPUnit\InteractsWithCassettes;
use HttpVcr\Bridge\PHPUnit\OpensDeclaredCassette;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Exception\StrictModeViolationException;
use HttpVcr\RecordMode;
use HttpVcr\StrictMode;
use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The bridge, run by the thing it bridges: these tests are themselves the tests the
 * extension opens cassettes for, which is the only way to find out whether the attribute,
 * the trait and the hooks agree with each other (§3.12).
 *
 * They replay committed cassettes under PlaybackOnly, so nothing here can reach a network
 * however the suite is run.
 */
#[CoversClass(Extension::class)]
#[CoversClass(OpensDeclaredCassette::class)]
#[CoversClass(CassetteFactory::class)]
#[CoversClass(CurrentCassetteSession::class)]
#[CoversClass(InteractsWithCassettes::class)]
#[CoversClass(UseCassette::class)]
final class PHPUnitBridgeTest extends BridgeTestCase
{
    private bool $playbackHookRan = false;

    protected function setUp(): void
    {
        if (!CurrentCassetteSession::isActive()) {
            return;
        }

        // The window §3.14 promises: the client exists and is still unfrozen here, which is
        // where a project registers its redaction and hooks.
        $this->vcrClient()->beforePlayback(function (Interaction $interaction): Interaction {
            $this->playbackHookRan = true;

            return $interaction;
        });
    }

    #[UseCassette('bridge/greeting', RecordMode::PlaybackOnly)]
    public function testTheAttributeHandsTheTestAClientBoundToItsCassette(): void
    {
        $response = $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/greeting'));

        self::assertSame('{"hello":"world"}', (string) $response->getBody());
    }

    #[UseCassette('bridge/greeting', RecordMode::PlaybackOnly)]
    public function testTheClientIsConfigurableBySetUpBeforeTheTestBodyRuns(): void
    {
        $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/greeting'));

        self::assertTrue($this->playbackHookRan, 'the hook registered in setUp never saw the interaction');
    }

    /**
     * Each method gets its own session against the same file — this one replays the very
     * interaction the method above it consumed.
     */
    #[UseCassette('bridge/greeting', RecordMode::PlaybackOnly)]
    public function testEveryMethodGetsASessionOfItsOwnAgainstTheSameFile(): void
    {
        $response = $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/greeting'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[UseCassette('bridge/two-calls', RecordMode::PlaybackOnly, strictMode: StrictMode::AllPlayed)]
    public function testAStrictCassetteIsSatisfiedByReplayingAllOfIt(): void
    {
        $products = $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/products'));
        $product = $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/products/1'));

        self::assertSame('{"products":[1,2]}', (string) $products->getBody());
        self::assertSame('{"id":1}', (string) $product->getBody());
    }

    /**
     * The other half of the strict promise, and the reason the trait closes the session
     * from an `#[After]` method: the failure has to reach the test rather than the runner.
     */
    public function testAnUnplayedInteractionFailsWhenTheSessionCloses(): void
    {
        $this->expectException(StrictModeViolationException::class);

        $this->useCassette(
            'bridge/two-calls',
            function (): void {
                $this->vcrClient()->sendRequest(new Request('GET', 'https://api.example.com/products'));
            },
            RecordMode::PlaybackOnly,
            StrictMode::AllPlayed,
        );
    }

    public function testTheClosureFormOpensASessionWithoutAnAttribute(): void
    {
        $body = $this->useCassette(
            'bridge/greeting',
            fn (): string => (string) $this->vcrClient()
                ->sendRequest(new Request('GET', 'https://api.example.com/greeting'))
                ->getBody(),
            RecordMode::PlaybackOnly,
        );

        self::assertSame('{"hello":"world"}', $body);
        self::assertFalse(CurrentCassetteSession::isActive(), 'the closure form left its session open');
    }

    /**
     * The one case the library can catch of the extension not being registered at all: the
     * method a test reaches for anyway.
     */
    public function testWithNoCassetteOpenTheTraitSaysWhatIsMissing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HttpVcr\Bridge\PHPUnit\Extension');

        $this->vcrClient();
    }
}
