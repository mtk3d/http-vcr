<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Hook;

use DateTimeImmutable;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\Hook\Redaction;
use HttpVcr\Hook\RedactionHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RedactionHooks::class)]
#[CoversClass(Redaction::class)]
final class RedactionHooksTest extends TestCase
{
    private static function interaction(
        RecordedRequest $request,
        ?RecordedResponse $response = null,
    ): Interaction {
        return Interaction::recorded(
            $request,
            $response ?? new RecordedResponse(200),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function request(
        string $uri = 'https://api.example.com/orders',
        array $headers = [],
        string $body = '',
        ?string $encoding = null,
    ): RecordedRequest {
        return new RecordedRequest('POST', $uri, $headers, $body, $encoding);
    }

    public function testAValueIsReplacedWhereverItAppears(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redact('<API_KEY>', static fn (): string => 'secret-token');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(
                'https://api.example.com/orders?key=secret-token',
                ['X-Api-Key' => ['secret-token']],
                '{"key":"secret-token"}',
            ),
            new RecordedResponse(200, ['X-Echo' => ['secret-token']], '{"token":"secret-token"}'),
        ));

        self::assertSame('https://api.example.com/orders?key=<API_KEY>', $recorded->request->uri);
        self::assertSame(['<API_KEY>'], $recorded->request->header('X-Api-Key'));
        self::assertSame('{"key":"<API_KEY>"}', $recorded->request->body);
        $response = $recorded->response;
        self::assertNotNull($response);
        self::assertSame(['<API_KEY>'], $response->header('X-Echo'));
        self::assertSame('{"token":"<API_KEY>"}', $response->body);
    }

    public function testAValueIsPutBackOnPlayback(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redact('<API_KEY>', static fn (): string => 'secret-token');

        $replayed = $hooks->beforePlayback(self::interaction(
            self::request('https://api.example.com/orders', ['X-Api-Key' => ['<API_KEY>']]),
            new RecordedResponse(200, [], '{"refresh_token":"<API_KEY>"}'),
        ));

        self::assertSame(['secret-token'], $replayed->request->header('X-Api-Key'));
        self::assertSame('{"refresh_token":"secret-token"}', $replayed->response?->body);
    }

    public function testTheRealValueIsReadWhenItIsNeededRatherThanWhenTheRuleIsDeclared(): void
    {
        $hooks = new RedactionHooks;
        $token = null;
        $hooks->redact('<API_KEY>', static function () use (&$token): ?string {
            return $token;
        });

        // Declared before the value exists — a test that sets its environment in setUp()
        // would otherwise register a rule that searches for nothing.
        $token = 'secret-token';

        $recorded = $hooks->beforeRecord(self::interaction(self::request(body: 'key=secret-token')));

        self::assertSame('key=<API_KEY>', $recorded->request->body);
    }

    public function testAHeaderIsRedactedInBothHalvesOfTheInteraction(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('X-Api-Key');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(headers: ['X-Api-Key' => ['secret-token']]),
            new RecordedResponse(200, ['x-api-key' => ['echoed-token']]),
        ));

        self::assertSame(['<REDACTED-X-API-KEY>'], $recorded->request->header('X-Api-Key'));
        self::assertSame(['<REDACTED-X-API-KEY>'], $recorded->response?->header('X-Api-Key'));
    }

    public function testARedactedHeaderKeepsTheCapitalizationItWasSentWith(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('x-api-key');

        $recorded = $hooks->beforeRecord(self::interaction(self::request(headers: ['X-Api-Key' => ['secret']])));

        self::assertSame(['X-Api-Key' => ['<REDACTED-X-API-KEY>']], $recorded->request->headers);
    }

    public function testAWriteOnlyHeaderRuleHasNothingToRestore(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('X-Api-Key');

        $replayed = $hooks->beforePlayback(self::interaction(
            self::request(headers: ['X-Api-Key' => ['<REDACTED-X-API-KEY>']]),
        ));

        self::assertSame(['<REDACTED-X-API-KEY>'], $replayed->request->header('X-Api-Key'));
    }

    public function testATwoWayHeaderRuleRestoresTheRealValue(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('X-Api-Key', static fn (): string => 'secret-token');

        $replayed = $hooks->beforePlayback(self::interaction(
            self::request(headers: ['X-Api-Key' => ['<REDACTED-X-API-KEY>']]),
        ));

        self::assertSame(['secret-token'], $replayed->request->header('X-Api-Key'));
    }

    /**
     * A cassette recorded before the rule existed holds the real value, and putting the
     * provider's value there would be a guess about a field nobody redacted.
     */
    public function testRestoringLeavesAValueThatIsNotThePlaceholderAlone(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('X-Api-Key', static fn (): string => 'secret-token');

        $replayed = $hooks->beforePlayback(self::interaction(
            self::request(headers: ['X-Api-Key' => ['some-other-token']]),
        ));

        self::assertSame(['some-other-token'], $replayed->request->header('X-Api-Key'));
    }

    public function testAJsonFieldIsRedactedByPointer(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactJsonField('/customer/email');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(body: '{"customer":{"email":"buyer@example.com","id":7}}'),
        ));

        self::assertSame(
            '{"customer":{"email":"<REDACTED-CUSTOMER-EMAIL>","id":7}}',
            $recorded->request->body,
        );
    }

    public function testAJsonFieldRuleLeavesABodyWithoutThatFieldAlone(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactJsonField('/customer/email');

        $body = '{"customer":{"id":7}}';
        $recorded = $hooks->beforeRecord(self::interaction(self::request(body: $body)));

        self::assertSame($body, $recorded->request->body);
    }

    public function testAJsonFieldRuleLeavesANonJsonBodyAlone(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactJsonField('/email');

        $recorded = $hooks->beforeRecord(self::interaction(self::request(body: 'email=buyer@example.com')));

        self::assertSame('email=buyer@example.com', $recorded->request->body);
    }

    public function testAQueryParameterIsRedactedInTheUrl(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactQueryParam('api_key');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request('https://api.example.com/orders?page=2&api_key=secret-token&sort=asc'),
        ));

        self::assertSame(
            'https://api.example.com/orders?page=2&api_key=<REDACTED-API-KEY>&sort=asc',
            $recorded->request->uri,
        );
    }

    public function testAQueryParameterIsRestoredUrlEncoded(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactQueryParam('api_key', static fn (): string => 'a b/c');

        $replayed = $hooks->beforePlayback(self::interaction(
            self::request('https://api.example.com/orders?api_key=<REDACTED-API-KEY>#top'),
        ));

        self::assertSame('https://api.example.com/orders?api_key=a%20b%2Fc#top', $replayed->request->uri);
    }

    public function testAFormFieldIsRedactedInAFormEncodedBody(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactFormField('client_secret');

        $recorded = $hooks->beforeRecord(self::interaction(self::request(
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            body: 'grant_type=client_credentials&client_secret=shhh&scope=read',
        )));

        self::assertSame(
            'grant_type=client_credentials&client_secret=<REDACTED-CLIENT-SECRET>&scope=read',
            $recorded->request->body,
        );
    }

    public function testAFormFieldRuleIgnoresABodyThatIsNotAForm(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactFormField('client_secret');

        $recorded = $hooks->beforeRecord(self::interaction(self::request(
            headers: ['Content-Type' => ['application/json']],
            body: '{"client_secret":"shhh"}',
        )));

        self::assertSame('{"client_secret":"shhh"}', $recorded->request->body);
    }

    /**
     * HTTP client exceptions routinely quote the full request URL, query string and all,
     * so a cassette that redacts the URI and not the message leaks anyway.
     */
    public function testTheMessageOfARecordedFailureIsRedactedToo(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactQueryParam('api_key');
        $hooks->redact('<HOST_SECRET>', static fn (): string => 'internal-host');

        $failed = Interaction::failed(
            self::request('https://api.example.com/orders?api_key=secret-token'),
            new RecordedError(
                ErrorCategory::Network,
                'cURL error 6: Could not resolve internal-host (see https://api.example.com/orders?api_key=secret-token)',
                RuntimeException::class,
            ),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $recorded = $hooks->beforeRecord($failed);

        self::assertSame(
            'cURL error 6: Could not resolve <HOST_SECRET> '
            .'(see https://api.example.com/orders?api_key=<REDACTED-API-KEY>)',
            $recorded->error?->message,
        );
    }

    public function testABinaryBodyIsLeftAlone(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redact('<SECRET>', static fn (): string => "\x01\x02");

        $body = "\x00\x01\x02\x03";
        $recorded = $hooks->beforeRecord(self::interaction(self::request(body: $body, encoding: 'base64')));

        self::assertSame($body, $recorded->request->body);
    }

    public function testRulesApplyInRegistrationOrder(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redact('<OUTER>', static fn (): string => 'Bearer inner-token');
        $hooks->redact('<INNER>', static fn (): string => 'inner-token');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(headers: ['X-Auth' => ['Bearer inner-token']]),
        ));

        self::assertSame(['<OUTER>'], $recorded->request->header('X-Auth'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveHeaders(): iterable
    {
        yield 'Authorization' => ['Authorization'];
        yield 'Proxy-Authorization' => ['Proxy-Authorization'];
        yield 'Cookie' => ['Cookie'];
        yield 'Set-Cookie' => ['Set-Cookie'];
    }

    #[DataProvider('sensitiveHeaders')]
    public function testRedactsTheAuthorizationHeadersWithNoConfigurationAtAll(string $header): void
    {
        $recorded = (new RedactionHooks)->beforeRecord(self::interaction(
            self::request(headers: [$header => ['Bearer sk_live_4eC39H']]),
            new RecordedResponse(200, [$header => ['Bearer sk_live_4eC39H']]),
        ));

        self::assertSame([Redaction::placeholderFor($header)], $recorded->request->header($header));
        self::assertSame([Redaction::placeholderFor($header)], $recorded->response?->header($header));
    }

    public function testAutomaticRedactionRunsAheadOfEveryDeclaredRule(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redact('<TOKEN>', static fn (): string => 'sk_live_4eC39H');

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(headers: ['Authorization' => ['Bearer sk_live_4eC39H']]),
        ));

        self::assertSame(['<REDACTED-AUTHORIZATION>'], $recorded->request->header('Authorization'));
    }

    public function testAnAutomaticallyRedactedHeaderStopsTellingTwoRequestsApart(): void
    {
        $incoming = (new RedactionHooks)->forMatching(
            self::request(headers: ['Authorization' => ['Bearer sk_live_4eC39H']]),
        );

        self::assertSame(['<REDACTED-AUTHORIZATION>'], $incoming->header('Authorization'));
    }

    public function testAnIncludedHeaderIsNeitherRedactedNorNormalizedForMatching(): void
    {
        $hooks = new RedactionHooks;
        $hooks->includeSensitiveHeaders(['authorization']);

        $request = self::request(headers: ['Authorization' => ['Bearer sk_live_4eC39H']]);

        self::assertSame(['Bearer sk_live_4eC39H'], $hooks->beforeRecord(self::interaction($request))->request->header('Authorization'));
        self::assertSame(['Bearer sk_live_4eC39H'], $hooks->forMatching($request)->header('Authorization'));
    }

    public function testIncludingOneHeaderLeavesTheOtherThreeRedacted(): void
    {
        $hooks = new RedactionHooks;
        $hooks->includeSensitiveHeaders(['Authorization']);

        $recorded = $hooks->beforeRecord(self::interaction(
            self::request(headers: ['Authorization' => ['Bearer a'], 'Cookie' => ['session=b']]),
        ));

        self::assertSame(['Bearer a'], $recorded->request->header('Authorization'));
        self::assertSame(['<REDACTED-COOKIE>'], $recorded->request->header('Cookie'));
    }

    public function testMatchingSeesOnlyTheWriteOnlyRulesAppliedToTheIncomingRequest(): void
    {
        $hooks = new RedactionHooks;
        $hooks->redactHeader('X-Api-Key');
        $hooks->redactHeader('X-Account', static fn (): string => 'acct-1');

        $incoming = $hooks->forMatching(self::request(
            headers: ['X-Api-Key' => ['live-token'], 'X-Account' => ['acct-1']],
        ));

        self::assertSame(['<REDACTED-X-API-KEY>'], $incoming->header('X-Api-Key'));
        self::assertSame(['acct-1'], $incoming->header('X-Account'));
    }

    public function testAnInteractionWithNothingToRedactComesOutUnchanged(): void
    {
        $hooks = new RedactionHooks;
        $interaction = self::interaction(self::request());

        self::assertEquals($interaction, $hooks->beforeRecord($interaction));
        self::assertSame($interaction, $hooks->beforePlayback($interaction));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function generatedPlaceholders(): iterable
    {
        yield 'header' => ['X-Api-Key', '<REDACTED-X-API-KEY>'];
        yield 'snake case' => ['api_key', '<REDACTED-API-KEY>'];
        yield 'json pointer' => ['/customer/email', '<REDACTED-CUSTOMER-EMAIL>'];
        yield 'already upper case' => ['CLIENT_SECRET', '<REDACTED-CLIENT-SECRET>'];
    }

    #[DataProvider('generatedPlaceholders')]
    public function testGeneratesAReadablePlaceholderFromAFieldName(string $name, string $placeholder): void
    {
        self::assertSame($placeholder, Redaction::placeholderFor($name));
    }
}
