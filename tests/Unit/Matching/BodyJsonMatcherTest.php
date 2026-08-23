<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Matching;

use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Matching\BodyJsonMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BodyJsonMatcher::class)]
final class BodyJsonMatcherTest extends TestCase
{
    private static function request(string $body, ?string $encoding = null): RecordedRequest
    {
        return new RecordedRequest('POST', 'https://example.com/orders', [], $body, $encoding);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentDocuments(): iterable
    {
        yield 'key order' => ['{"a":1,"b":2}', '{"b":2,"a":1}'];
        yield 'whitespace' => ['{"a":1}', "{\n  \"a\": 1\n}"];
        yield 'nested key order' => ['{"o":{"a":1,"b":2}}', '{"o":{"b":2,"a":1}}'];
        yield 'array order kept' => ['{"tags":["a","b"]}', '{"tags":["a","b"]}'];
        yield 'a scalar document' => ['42', '42'];
        yield 'null' => ['{"a":null}', '{"a":null}'];
    }

    #[DataProvider('equivalentDocuments')]
    public function testComparesJsonSemantically(string $recorded, string $incoming): void
    {
        self::assertTrue((new BodyJsonMatcher)->matches(self::request($recorded), self::request($incoming)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function differentDocuments(): iterable
    {
        yield 'value' => ['{"status":"active"}', '{"status":"pending"}', 'field "status" expected "active", got "pending"'];
        yield 'scalar type' => ['{"amount":100}', '{"amount":"100"}', 'field "amount" expected 100, got "100"'];
        yield 'int and float' => ['{"amount":100}', '{"amount":100.5}', 'field "amount" expected 100, got 100.5'];
        yield 'missing field' => ['{"a":1,"b":2}', '{"a":1}', 'field "b" missing'];
        yield 'extra field' => ['{"a":1}', '{"a":1,"b":2}', 'unexpected field "b"'];
        yield 'nested value' => ['{"o":{"a":1}}', '{"o":{"a":2}}', 'field "o/a" expected 1, got 2'];
        yield 'array order' => ['{"tags":["a","b"]}', '{"tags":["b","a"]}', 'field "tags/0" expected "a", got "b"'];
        yield 'array length' => ['{"tags":["a"]}', '{"tags":["a","b"]}', 'field "tags" expected 1 element, got 2'];
        yield 'object where an array was recorded' => ['{"tags":[]}', '{"tags":{}}', 'field "tags" expected an array, got an object'];
        yield 'root scalar' => ['42', '43', 'expected 42, got 43'];
    }

    #[DataProvider('differentDocuments')]
    public function testNamesTheFieldThatDiffers(string $recorded, string $incoming, string $detail): void
    {
        $matcher = new BodyJsonMatcher;

        self::assertFalse($matcher->matches(self::request($recorded), self::request($incoming)));
        self::assertSame($detail, $matcher->explainMismatch(self::request($recorded), self::request($incoming)));
    }

    public function testFallsBackToARawComparisonWhenEitherSideIsNotJson(): void
    {
        $matcher = new BodyJsonMatcher;

        self::assertTrue($matcher->matches(self::request('name=acme'), self::request('name=acme')));
        self::assertFalse($matcher->matches(self::request('name=acme'), self::request('name=other')));
        self::assertSame(
            'raw body: expected "name=acme", got "name=other"',
            $matcher->explainMismatch(self::request('name=acme'), self::request('name=other')),
        );
    }

    public function testIgnoresAFieldThatChangesEveryRun(): void
    {
        $matcher = (new BodyJsonMatcher)->ignoreJsonField('/transactionId');

        self::assertTrue($matcher->matches(
            self::request('{"transactionId":"11111111-1111-1111-1111-111111111111","amount":100}'),
            self::request('{"transactionId":"22222222-2222-2222-2222-222222222222","amount":100}'),
        ));
        self::assertFalse($matcher->matches(
            self::request('{"transactionId":"1","amount":100}'),
            self::request('{"transactionId":"2","amount":200}'),
        ));
    }

    public function testIgnoresANestedFieldAndOneMissingOnOneSide(): void
    {
        $matcher = (new BodyJsonMatcher)->ignoreJsonField('/meta/requestedAt');

        self::assertTrue($matcher->matches(
            self::request('{"meta":{"requestedAt":"2026-01-01T00:00:00Z","page":1}}'),
            self::request('{"meta":{"page":1}}'),
        ));
    }

    public function testMatchesAFieldByPatternInsteadOfByValue(): void
    {
        $matcher = (new BodyJsonMatcher)->matchJsonField('/requestId', '/^[0-9a-f-]{36}$/');

        self::assertTrue($matcher->matches(
            self::request('{"requestId":"11111111-1111-1111-1111-111111111111"}'),
            self::request('{"requestId":"22222222-2222-2222-2222-222222222222"}'),
        ));
    }

    public function testRejectsAPatternedFieldThatDoesNotLookRight(): void
    {
        $matcher = (new BodyJsonMatcher)->matchJsonField('/requestId', '/^[0-9a-f-]{36}$/');
        $recorded = self::request('{"requestId":"11111111-1111-1111-1111-111111111111"}');
        $incoming = self::request('{"requestId":"nope"}');

        self::assertFalse($matcher->matches($recorded, $incoming));
        self::assertSame(
            'field "requestId" expected to match /^[0-9a-f-]{36}$/, got "nope"',
            $matcher->explainMismatch($recorded, $incoming),
        );
    }

    public function testRejectsAPatternedFieldThatIsAbsent(): void
    {
        $matcher = (new BodyJsonMatcher)->matchJsonField('/requestId', '/^\d+$/');

        self::assertSame(
            'field "requestId" missing',
            $matcher->explainMismatch(self::request('{"requestId":"1"}'), self::request('{}')),
        );
    }

    public function testBuildersReturnANewMatcherRatherThanConfiguringThisOne(): void
    {
        $matcher = new BodyJsonMatcher;
        $configured = $matcher->ignoreJsonField('/a')->matchJsonField('/b', '/^\d+$/');

        self::assertNotSame($matcher, $configured);
        self::assertFalse($matcher->matches(self::request('{"a":1,"b":"1"}'), self::request('{"a":2,"b":"2"}')));
        self::assertTrue($configured->matches(self::request('{"a":1,"b":"1"}'), self::request('{"a":2,"b":"2"}')));
    }

    public function testRejectsAnUnusablePatternWhereItWasWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BodyJsonMatcher)->matchJsonField('/requestId', 'not-a-regex');
    }

    public function testExplainsNothingWhenTheDocumentsAgree(): void
    {
        self::assertNull((new BodyJsonMatcher)->explainMismatch(
            self::request('{"a":1}'),
            self::request('{"a":1}'),
        ));
    }
}
