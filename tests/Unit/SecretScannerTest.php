<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit;

use DateTimeImmutable;
use HttpVcr\Cassette\ErrorCategory;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedError;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\SecretFinding;
use HttpVcr\SecretScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SecretScanner::class)]
#[CoversClass(SecretFinding::class)]
final class SecretScannerTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function interaction(
        string $uri = 'https://api.example.com/orders',
        array $headers = [],
        string $requestBody = '',
        ?RecordedResponse $response = null,
    ): Interaction {
        return Interaction::recorded(
            new RecordedRequest('POST', $uri, $headers, $requestBody),
            $response ?? new RecordedResponse(200),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    /**
     * @return list<string>
     */
    private static function locations(Interaction $interaction): array
    {
        return array_map(
            static fn (SecretFinding $finding): string => $finding->location,
            (new SecretScanner)->scan($interaction),
        );
    }

    /**
     * Deliberately no real vendor's prefix on the live/test key: the rule it exercises is
     * `[a-z]{2,4}_(live|test)_…`, which a made-up prefix covers exactly as well, and a
     * fixture shaped like a genuine provider's key sets off every scanner that ever reads
     * this repository — including this library's own.
     *
     * @return iterable<string, array{string}>
     */
    public static function credentialShapedValues(): iterable
    {
        yield 'AWS access key' => ['AKIAIOSFODNN7EXAMPLE'];
        yield 'vendor-prefixed live key' => ['tk_live_9f8e7d6c5b4a3210FEDCBA'];
        yield 'vendor-prefixed test key' => ['zz_test_1a2b3c4d5e6f7890ABCDEF'];
        yield 'GitHub token' => ['ghp_16C7e42F292c6912E7710c838347Ae178B4a'];
        yield 'Slack token' => ['xoxb-123456789012-abcdefghijklm'];
        yield 'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'];
    }

    #[DataProvider('credentialShapedValues')]
    public function testFindsACredentialShapeInAResponseBody(string $secret): void
    {
        $interaction = self::interaction(response: new RecordedResponse(200, [], 'the key is '.$secret));

        self::assertSame(['response.body'], self::locations($interaction));
    }

    public function testFindsABearerTokenInAHeaderThatEscapedRedaction(): void
    {
        $interaction = self::interaction(headers: ['Authorization' => ['Bearer 4eC39HqLyjWDarjtT1zdp7dc']]);

        self::assertSame(['request.headers.authorization'], self::locations($interaction));
    }

    public function testFindsAValueUnderACredentialShapedFieldName(): void
    {
        $interaction = self::interaction(response: new RecordedResponse(
            200,
            [],
            '{"customer":{"session_token":"abcd1234efgh"}}',
        ));

        self::assertSame(['response.body (/customer/session_token)'], self::locations($interaction));
    }

    public function testFindsAGeneratedLookingTokenWhateverTheFieldIsCalled(): void
    {
        $interaction = self::interaction(response: new RecordedResponse(
            200,
            [],
            '{"handle":"n7Yq2vB8xK1mR4tW9pL3zC6aH5sD0fG2"}',
        ));

        self::assertSame(['response.body (/handle)'], self::locations($interaction));
    }

    public function testFindsAKeyInTheQueryString(): void
    {
        $interaction = self::interaction('https://api.example.com/orders?page=2&api_key=abcd1234efgh');

        self::assertSame(['request.uri (api_key)'], self::locations($interaction));
    }

    public function testFindsAFormFieldInARequestBody(): void
    {
        $interaction = self::interaction(
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            requestBody: 'grant_type=client_credentials&client_secret=abcd1234efgh',
        );

        self::assertSame(['request.body (client_secret)'], self::locations($interaction));
    }

    /**
     * Client exception messages routinely quote the request URL, so a failure recorded
     * without redaction leaks the same way a response body would.
     */
    public function testFindsACredentialInTheMessageOfARecordedFailure(): void
    {
        $failed = Interaction::failed(
            new RecordedRequest('GET', 'https://api.example.com/orders'),
            new RecordedError(
                ErrorCategory::Network,
                'cURL error 7 for https://api.example.com/orders?token=tk_live_9f8e7d6c5b4a',
                RuntimeException::class,
            ),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame(['error.message'], self::locations($failed));
    }

    /**
     * The one thing a scan must never report — otherwise a properly redacted cassette warns
     * on every run and the warning stops meaning anything.
     */
    public function testSaysNothingAboutARedactedValue(): void
    {
        $interaction = self::interaction(
            'https://api.example.com/orders?api_key=<REDACTED-API-KEY>',
            ['Authorization' => ['<REDACTED-AUTHORIZATION>']],
            response: new RecordedResponse(200, [], '{"refresh_token":"<REDACTED-REFRESH-TOKEN>"}'),
        );

        self::assertSame([], self::locations($interaction));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryPayloads(): iterable
    {
        yield 'plain values' => ['{"title":"T-Shirt","price":19.99,"id":42}'];
        yield 'a date' => ['{"created_at":"2026-08-21T10:00:00+00:00"}'];
        yield 'a uuid' => ['{"order_id":"11111111-1111-1111-1111-111111111111"}'];
        yield 'a sentence' => ['{"description":"A shirt with a long description of it"}'];
    }

    #[DataProvider('ordinaryPayloads')]
    public function testLeavesOrdinaryContentAlone(string $body): void
    {
        self::assertSame([], self::locations(self::interaction(response: new RecordedResponse(200, [], $body))));
    }

    public function testDoesNotLookInsideABinaryBody(): void
    {
        $interaction = Interaction::recorded(
            new RecordedRequest('POST', 'https://api.example.com/orders'),
            new RecordedResponse(200, [], 'AKIAIOSFODNN7EXAMPLE', 'base64'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame([], self::locations($interaction));
    }

    public function testReportsTheSameValueOnce(): void
    {
        $interaction = self::interaction(
            headers: ['X-Api-Key' => ['tk_live_9f8e7d6c5b4a3210FEDCBA']],
            response: new RecordedResponse(200, [], '{"key":"tk_live_9f8e7d6c5b4a3210FEDCBA"}'),
        );

        self::assertCount(1, (new SecretScanner)->scan($interaction));
    }

    public function testTheWarningNamesTheCassetteAndShowsOnlyEnoughOfTheValue(): void
    {
        $findings = [new SecretFinding('response.body', 'tk_live_9f8e7d6c5b4a3210FEDCBA')];

        self::assertSame(
            "http-vcr: recorded 1 interaction → tests/Cassettes/payments.json\n"
            ."  response.body carries a credential-shaped value, stored unredacted:\n"
            ."    \"tk_live…\" (30 chars)\n",
            SecretScanner::warning('tests/Cassettes/payments.json', 1, $findings),
        );
    }

    public function testTheWarningCountsInteractionsNotFindings(): void
    {
        $warning = SecretScanner::warning('tests/Cassettes/payments.json', 3, [
            new SecretFinding('response.body', 'sk_live_one'),
            new SecretFinding('request.uri (api_key)', 'sk_live_two'),
        ]);

        self::assertStringContainsString('recorded 3 interactions', $warning);
        self::assertStringContainsString('request.uri (api_key)', $warning);
    }
}
