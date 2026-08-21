<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Integration;

use HttpVcr\Cassette\CassetteManager;
use HttpVcr\Config;
use HttpVcr\Exception\NoMatchingInteractionException;
use HttpVcr\Hook\RedactionHooks;
use HttpVcr\Matching\HeadersMatcher;
use HttpVcr\Matching\MethodMatcher;
use HttpVcr\Matching\QueryStringMatcher;
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
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcrClient::class)]
#[CoversClass(RedactionHooks::class)]
#[CoversClass(CassetteManager::class)]
final class RedactionTest extends TestCase
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

    public function testASecretNeverReachesTheCassetteFile(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'));
        $vcr->redact('<API_KEY>', static fn (): string => 'sk_live_4eC39H');

        $vcr->sendRequest(
            (new Request('GET', 'https://api.example.com/orders'))->withHeader('X-Api-Key', 'sk_live_4eC39H'),
        );

        self::assertStringNotContainsString('sk_live_4eC39H', $this->cassettes->read('payments.json'));
        self::assertStringContainsString('<API_KEY>', $this->cassettes->read('payments.json'));
    }

    /**
     * The php-vcr #503 case, 1:1: a token the application reads out of the *response* and
     * uses afterwards. Redacting it one way would hand the code under test the string
     * "<API_KEY>" and fail the test somewhere else entirely.
     */
    public function testATwoWayRuleGivesTheCodeUnderTestTheRealValueBackFromTheResponse(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"refresh_token":"sk_live_4eC39H"}'));
        $vcr->redactJsonField('/refresh_token', static fn (): string => 'sk_live_4eC39H');
        $vcr->sendRequest(new Request('POST', 'https://api.example.com/token'));
        $vcr->close();

        self::assertSame(
            '{"refresh_token":"<REDACTED-REFRESH-TOKEN>"}',
            $this->cassettes->cassette('payments.json')->responseBody(0),
        );

        $replayed = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly);
        $replayed->redactJsonField('/refresh_token', static fn (): string => 'sk_live_4eC39H');
        $response = $replayed->sendRequest(new Request('POST', 'https://api.example.com/token'));

        self::assertSame('{"refresh_token":"sk_live_4eC39H"}', (string) $response->getBody());
    }

    /**
     * Turning redaction on must not break replay — which is what would happen if a matcher
     * compared a placeholder in the cassette against a live token in the request.
     */
    public function testATwoWayRedactedHeaderStillMatchesOnReplay(): void
    {
        $matchers = [new MethodMatcher(), new UriMatcher(), new HeadersMatcher(['X-Api-Key'])];

        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'), matchers: $matchers);
        $vcr->redactHeader('X-Api-Key', static fn (): string => 'sk_live_4eC39H');
        $vcr->sendRequest($this->authorized());
        $vcr->close();

        $replayed = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly, $matchers);
        $replayed->redactHeader('X-Api-Key', static fn (): string => 'sk_live_4eC39H');
        $response = $replayed->sendRequest($this->authorized());

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    /**
     * The same, for a rule that has no way back: there the incoming request is redacted
     * instead, so both sides carry the same placeholder.
     */
    public function testAWriteOnlyRedactedHeaderStillMatchesOnReplay(): void
    {
        $matchers = [new MethodMatcher(), new UriMatcher(), new HeadersMatcher(['X-Api-Key'])];

        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'), matchers: $matchers);
        $vcr->redactHeader('X-Api-Key');
        $vcr->sendRequest($this->authorized());
        $vcr->close();

        $replayed = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly, $matchers);
        $replayed->redactHeader('X-Api-Key');

        // A different token than the one recorded — a write-only redacted field stops
        // telling two interactions apart, which is the documented consequence.
        $response = $replayed->sendRequest(
            (new Request('GET', 'https://api.example.com/orders'))->withHeader('X-Api-Key', 'sk_live_OTHER'),
        );

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testARedactedQueryParameterStillMatchesOnReplay(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'));
        $vcr->redactQueryParam('api_key');
        $vcr->sendRequest(new Request('GET', 'https://api.example.com/orders?api_key=sk_live_4eC39H&page=2'));
        $vcr->close();

        self::assertSame(
            'https://api.example.com/orders?api_key=<REDACTED-API-KEY>&page=2',
            $this->cassettes->cassette('payments.json')->requestUri(0),
        );

        $replayed = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly, [
            new MethodMatcher(),
            new UriMatcher(),
            new QueryStringMatcher(),
        ]);
        $replayed->redactQueryParam('api_key');
        $response = $replayed->sendRequest(
            new Request('GET', 'https://api.example.com/orders?api_key=sk_live_4eC39H&page=2'),
        );

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testARedactedFormFieldNeverReachesTheCassette(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"access_token":"t"}'));
        $vcr->redactFormField('client_secret');

        $vcr->sendRequest(new Request(
            'POST',
            'https://api.example.com/oauth/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'grant_type=client_credentials&client_secret=shhh',
        ));

        self::assertSame(
            'grant_type=client_credentials&client_secret=<REDACTED-CLIENT-SECRET>',
            $this->cassettes->cassette('payments.json')->requestBody(0),
        );
    }

    public function testTheRecordingRunItselfStillSeesTheRealResponse(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond(
            new Response(200, ['X-Token' => 'sk_live_4eC39H'], '{"refresh_token":"sk_live_4eC39H"}'),
        ));
        $vcr->redactHeader('X-Token');
        $vcr->redactJsonField('/refresh_token');

        $response = $vcr->sendRequest(new Request('POST', 'https://api.example.com/token'));

        self::assertSame('{"refresh_token":"sk_live_4eC39H"}', (string) $response->getBody());
        self::assertSame(['sk_live_4eC39H'], $response->getHeader('X-Token'));
    }

    public function testTheAuthorizationHeaderIsRedactedWithNoConfigurationAtAll(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'));

        $vcr->sendRequest(
            (new Request('GET', 'https://api.example.com/orders'))->withHeader('Authorization', 'Bearer sk_live_4eC39H'),
        );

        $file = $this->cassettes->read('payments.json');
        self::assertStringNotContainsString('sk_live_4eC39H', $file);
        self::assertStringContainsString('<REDACTED-AUTHORIZATION>', $file);
    }

    /**
     * The documented consequence of a redaction the library has no way to reverse: two
     * recordings that differ only in that header are the same interaction to a matcher.
     */
    public function testAnAutomaticallyRedactedHeaderNoLongerTellsTwoRequestsApart(): void
    {
        $matchers = [new MethodMatcher(), new UriMatcher(), new HeadersMatcher(['Authorization'])];

        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'), matchers: $matchers);
        $vcr->sendRequest($this->bearing('sk_live_4eC39H'));
        $vcr->close();

        $response = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly, $matchers)
            ->sendRequest($this->bearing('sk_live_SOMETHING_ELSE'));

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testAnIncludedHeaderIsStoredAsSentAndTellsRequestsApartAgain(): void
    {
        // This cassette is meant to hold a real credential, so the automatic scan would be
        // right about it and has nothing to add — the opt-out exists for exactly this.
        VcrClient::configure(scanRecordingsForSecrets: false);

        $matchers = [new MethodMatcher(), new UriMatcher(), new HeadersMatcher(['Authorization'])];

        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'), matchers: $matchers);
        $vcr->includeSensitiveHeaders(['Authorization']);
        $vcr->sendRequest($this->bearing('sk_live_4eC39H'));
        $vcr->close();

        self::assertStringContainsString('sk_live_4eC39H', $this->cassettes->read('payments.json'));

        $replayed = $this->client(new FakeHttpClient(), RecordMode::PlaybackOnly, $matchers);
        $replayed->includeSensitiveHeaders(['Authorization']);

        $this->expectException(NoMatchingInteractionException::class);

        $replayed->sendRequest($this->bearing('sk_live_SOMETHING_ELSE'));
    }

    /**
     * For a secret shared by every cassette in a project, declared once instead of in every
     * test — and registered before anything the instance adds, so it runs first.
     */
    public function testAProjectWideRuleAppliesWithoutTouchingTheClient(): void
    {
        VcrClient::configure(redact: ['<COMPANY_PROXY_TOKEN>' => static fn (): string => 'sk_live_4eC39H']);

        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'));
        $vcr->sendRequest(
            (new Request('GET', 'https://api.example.com/orders'))->withHeader('X-Proxy', 'sk_live_4eC39H'),
        );
        $vcr->close();

        self::assertStringNotContainsString('sk_live_4eC39H', $this->cassettes->read('payments.json'));
        self::assertStringContainsString('<COMPANY_PROXY_TOKEN>', $this->cassettes->read('payments.json'));
    }

    public function testRedactionRegisteredAfterTheFirstRequestIsRefused(): void
    {
        $vcr = $this->client((new FakeHttpClient())->willRespond('{"ok":true}'));
        $vcr->sendRequest(new Request('GET', 'https://api.example.com/orders'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/redact\(\) has to be called before the first request/');

        $vcr->redact('<API_KEY>', static fn (): string => 'sk_live_4eC39H');
    }

    private function bearing(string $token): Request
    {
        return (new Request('GET', 'https://api.example.com/orders'))->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function authorized(): Request
    {
        return (new Request('GET', 'https://api.example.com/orders'))->withHeader('X-Api-Key', 'sk_live_4eC39H');
    }

    /**
     * The findings have somewhere to go other than standard error, which is what lets a
     * test runner gather a whole run's worth and print them in one block (§3.4).
     */
    public function testTheScanReportsToWhereverTheCallerAskedFor(): void
    {
        $warnings = [];

        $vcr = new VcrClient(
            (new FakeHttpClient())->willRespond('{"ok":true}'),
            'payments',
            persister: $this->cassettes->persister(),
            warn: static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );
        $vcr->sendRequest(
            (new Request('POST', 'https://api.example.com/charges'))
                ->withBody(Stream::create('{"api_key":"n0tar3alcr3dent1albutshapedl1keone"}')),
        );
        $vcr->close();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('request.body', $warnings[0]);
        self::assertStringContainsString('credential-shaped value', $warnings[0]);
    }

    /**
     * @param list<RequestMatcherInterface> $matchers
     */
    private function client(
        FakeHttpClient $inner,
        RecordMode $mode = RecordMode::RecordIfAbsent,
        array $matchers = [],
    ): VcrClient {
        return new VcrClient($inner, 'payments', $mode, $matchers, persister: $this->cassettes->persister());
    }
}
