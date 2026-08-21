<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\Tests\Support\CassetteDirectory;
use HttpVcr\Tests\Support\ControlsEnvironment;
use HttpVcr\Tests\Support\FakeHttpClient;
use HttpVcr\VcrClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcrClient::class)]
#[CoversClass(CassetteManager::class)]
final class InteractionConsumptionTest extends TestCase
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

    public function testTheSameRequestTwiceReplaysTwoRecordingsInTheOrderTheyWereMade(): void
    {
        $this->recordTwice();

        $vcr = new VcrClient(new FakeHttpClient(), 'retry/poll', persister: $this->persister());

        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
        self::assertSame('{"status":"done"}', (string) $vcr->sendRequest($this->request())->getBody());
    }

    public function testAskingOnceMoreThanWasRecordedSaysTheCassetteIsExhausted(): void
    {
        $this->recordTwice();

        $vcr = new VcrClient(new FakeHttpClient(), 'retry/poll', persister: $this->persister());
        $vcr->sendRequest($this->request());
        $vcr->sendRequest($this->request());

        $this->expectException(NoMatchingInteractionException::class);
        $this->expectExceptionMessage('all 2 interactions were already consumed');

        $vcr->sendRequest($this->request());
    }

    public function testEachClientGetsItsOwnSessionSoTwoOfThemDoNotShareConsumption(): void
    {
        $this->recordTwice();

        $first = new VcrClient(new FakeHttpClient(), 'retry/poll', persister: $this->persister());
        $second = new VcrClient(new FakeHttpClient(), 'retry/poll', persister: $this->persister());

        self::assertSame('{"status":"pending"}', (string) $first->sendRequest($this->request())->getBody());
        self::assertSame('{"status":"pending"}', (string) $second->sendRequest($this->request())->getBody());
    }

    public function testARepeatableCassetteReplaysTheSameInteractionAsOftenAsItIsAsked(): void
    {
        $this->recordTwice();

        $vcr = new VcrClient(
            new FakeHttpClient(),
            'retry/poll',
            repeatablePlayback: true,
            persister: $this->persister(),
        );

        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
    }

    public function testASingleInteractionCanBeMarkedRepeatableInTheDataInstead(): void
    {
        $this->cassettes->write('retry/poll.json', <<<'JSON'
            {
                "schemaVersion": 1,
                "interactions": [
                    {
                        "request": {"method": "GET", "uri": "https://api.example.com/jobs/1", "headers": {}, "body": ""},
                        "response": {"status": 200, "headers": {}, "body": "{\"status\":\"pending\"}"},
                        "outcome": "success",
                        "recordedAt": "2026-08-21T10:00:00+00:00",
                        "repeatablePlayback": true
                    }
                ]
            }
            JSON);

        $vcr = new VcrClient(new FakeHttpClient(), 'retry/poll', persister: $this->persister());

        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
        self::assertSame('{"status":"pending"}', (string) $vcr->sendRequest($this->request())->getBody());
    }

    public function testARecordingSessionNeverReplaysWhatItJustRecorded(): void
    {
        $this->recordTwice();

        $cassette = $this->cassettes->cassette('retry/poll.json');

        self::assertSame(2, $cassette->count());
        self::assertSame('{"status":"pending"}', $cassette->responseBody(0));
        self::assertSame('{"status":"done"}', $cassette->responseBody(1));
    }

    public function testUnlessTheCassetteIsRepeatableInWhichCaseOneRecordingServesTheRepeats(): void
    {
        $inner = (new FakeHttpClient())->willRespond('{"status":"pending"}');

        $vcr = new VcrClient(
            $inner,
            'retry/poll',
            repeatablePlayback: true,
            persister: $this->persister(),
        );
        $vcr->sendRequest($this->request());
        $vcr->sendRequest($this->request());
        $vcr->close();

        self::assertSame(1, $inner->sentCount());
        self::assertSame(1, $this->cassettes->cassette('retry/poll.json')->count());
    }

    private function recordTwice(): void
    {
        $inner = (new FakeHttpClient())
            ->willRespond('{"status":"pending"}')
            ->willRespond('{"status":"done"}');

        $vcr = new VcrClient($inner, 'retry/poll', persister: $this->persister());
        $vcr->sendRequest($this->request());
        $vcr->sendRequest($this->request());
        $vcr->close();
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.example.com/jobs/1');
    }

    private function persister(): FilesystemCassettePersister
    {
        return $this->cassettes->persister();
    }
}
